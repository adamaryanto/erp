<?php

namespace App\Http\Controllers;

use App\Models\AiDiagnosis;
use App\Models\Basket;
use App\Models\ScaleStation;
use App\Models\Supplier;
use App\Models\Weighing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DiagnoseController extends Controller
{
    /**
     * Halaman Bug Log: tombol CEK ERROR + riwayat vonis AI.
     */
    public function index(): View
    {
        return view('buglog.index', [
            'diagnoses' => AiDiagnosis::orderByDesc('id')->limit(20)->get(),
            'stations' => ScaleStation::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    /**
     * Jalankan diagnosa: gabung data browser + server, tanya AI, simpan hasil.
     */
    public function diagnose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'client' => 'nullable|array',
        ]);

        $code = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');
        $client = $validated['client'] ?? [];

        $status = Cache::get('bridge_status:'.$code) ?? ($code === 'POS-01' ? Cache::get('bridge_status') : null);
        $statusAge = $status ? (int) abs(now()->diffInSeconds(Carbon::parse($status['at']))) : null;

        $payload = [
            'station' => $code,
            'client' => [
                'usb_supported' => (bool) ($client['usb_supported'] ?? false),
                'weight_source' => $client['weight_source'] ?? 'bridge',
                'page_connected' => (bool) ($client['page_connected'] ?? false),
                'backend_connected' => (bool) ($client['backend_connected'] ?? false),
                'usb_lines' => (int) ($client['usb_lines'] ?? 0),
                'usb_bytes' => (int) ($client['usb_bytes'] ?? 0),
                'usb_parse_ok' => (int) ($client['usb_parse_ok'] ?? 0),
                'usb_parse_fail' => (int) ($client['usb_parse_fail'] ?? 0),
                'usb_baud' => (int) ($client['usb_baud'] ?? 0),
                'usb_port' => substr((string) ($client['usb_port'] ?? ''), 0, 60),
                'usb_connected' => (bool) ($client['usb_connected'] ?? false),
                'usb_last_raw' => substr((string) ($client['usb_last_raw'] ?? ''), 0, 120),
                'raw_sample' => substr((string) ($client['raw_sample'] ?? ''), 0, 300),
                'api_reachable' => (bool) ($client['api_reachable'] ?? true),
            ],
            'server' => [
                'bridge_reported' => $status !== null,
                'bridge_fresh_30s' => $statusAge !== null && $statusAge <= 30,
                'bridge_age_seconds' => $statusAge,
                'bridge_hostname' => $status['hostname'] ?? null,
                'bridge_com' => $status['com_port'] ?? null,
                'recent_errors_today' => $this->recentErrorCount(),
            ],
        ];

        // Kasus dingin: tidak ada aktivitas sama sekali (bridge tidak pernah
        // lapor + browser tidak pernah terima data). Jangan vonis apa pun —
        // langsung kasih panduan mulai. Tanpa ini AI "ngarang" kambing hitam.
        $clientActive = ($payload['client']['usb_lines'] ?? 0) > 0
            || ($payload['client']['usb_bytes'] ?? 0) > 0
            || ($payload['client']['usb_connected'] ?? false)
            || ($payload['client']['page_connected'] ?? false)
            || ($payload['client']['backend_connected'] ?? false);
        $serverActive = $status !== null;

        if (! $clientActive && ! $serverActive) {
            $diagnosis = AiDiagnosis::create([
                'station' => $code,
                'verdict' => 'unknown',
                'title' => 'Belum ada aktivitas timbangan',
                'explanation' => 'Tidak ada jejak bridge maupun USB di server. Ini bukan error — memang belum ada yang dijalankan. Ikuti langkah mulai di bawah.',
                'steps' => [
                    'Colok kabel RS232-to-USB dari BX1 ke laptop pos.',
                    'Jalur USB: buka halaman timbang di Chrome, klik HUBUNGKAN USB, pilih port.',
                    'Jalur bridge: jalankan python bridge/reader.py --station '.$code,
                    'Tunggu LED hijau, lalu klik CEK ERROR lagi.',
                ],
                'payload' => $payload,
            ]);

            return response()->json(['diagnosis' => $diagnosis]);
        }

        [$evidence, $trace] = $this->collectEvidence($payload);
        $payload['evidence'] = $this->summarizeEvidence($evidence);
        $result = $this->askAi($payload, $evidence);
        $payload['trace'] = $result['trace'] ?? $trace;

        $diagnosis = AiDiagnosis::create([
            'station' => $code,
            'verdict' => $result['verdict'],
            'title' => $result['title'],
            'explanation' => $result['explanation'],
            'steps' => $result['steps'],
            'payload' => $payload,
        ]);

        return response()->json(['diagnosis' => $diagnosis]);
    }

    /**
     * Tool read-only yang boleh dipanggil AI saat investigasi.
     * TIDAK ADA tool tulis/eksekusi — eksekusi tetap lewat manusia.
     */
    private function aiTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_app_log',
                    'description' => 'Baca N baris terakhir log aplikasi (tanpa stack trace panjang).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['lines' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 30]],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_config',
                    'description' => 'Lihat konfigurasi aman: timezone, env, debug, driver DB/cache. TIDAK ada password/kunci.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_database',
                    'description' => 'Cek tabel penting ada/tidak + jumlah data master dan timbangan hari ini.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_deploy',
                    'description' => 'Deteksi deploy basi: cache route/config basi, umur file kode vs cache. Pemicu klasik halaman 500 setelah update.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_bridge',
                    'description' => 'Status bridge per pos: kapan terakhir lapor, COM apa, port terlihat apa.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['station' => ['type' => 'string']],
                        'required' => ['station'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Jalankan tool yang diminta AI (whitelist ketat, read-only).
     */
    private function runAiTool(string $name, array $args): string
    {
        try {
            return match ($name) {
                'read_app_log' => $this->toolReadLog((int) ($args['lines'] ?? 15)),
                'check_config' => json_encode([
                    'timezone' => config('app.timezone'),
                    'env' => config('app.env'),
                    'debug' => config('app.debug'),
                    'db' => config('database.default'),
                    'cache' => config('cache.default'),
                ]),
                'check_database' => $this->toolCheckDatabase(),
                'check_deploy' => $this->toolCheckDeploy(),
                'check_bridge' => $this->toolCheckBridge((string) ($args['station'] ?? 'POS-01')),
                default => 'Tool tidak dikenal.',
            };
        } catch (\Throwable $e) {
            return 'Tool gagal: '.$e->getMessage();
        }
    }

    private function toolReadLog(int $lines): string
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            return 'Tidak ada file log.';
        }
        $lines = max(5, min(30, $lines));
        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = array_slice($all, -$lines * 4);
        $out = [];
        // Hanya 45 menit terakhir: error lama (misal bekas debugging yang
        // sudah diperbaiki) tidak boleh ikut jadi bahan vonis.
        $cutoff = time() - 45 * 60;
        foreach ($tail as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\].*?(ERROR|WARNING|CRITICAL)/', $line, $m)) {
                if (strtotime($m[1]) < $cutoff) {
                    continue;
                }
                $out[] = substr($line, 0, 220);
            }
            if (count($out) >= $lines) {
                break;
            }
        }

        return $out === [] ? 'Tidak ada ERROR/WARNING dalam 45 menit terakhir.' : implode("\n", $out);
    }

    private function toolCheckDatabase(): string
    {
        $tables = ['suppliers', 'baskets', 'scale_stations', 'weighings', 'delivery_sessions', 'settings', 'ai_diagnoses'];
        $res = [];
        foreach ($tables as $table) {
            try {
                $exists = Schema::hasTable($table);
            } catch (\Throwable $e) {
                return 'KONEKSI DATABASE GAGAL: '.$e->getMessage();
            }
            $res[$table] = $exists ? 'ada' : 'HILANG';
        }
        try {
            $res['suppliers_rows'] = Supplier::count();
            $res['baskets_rows'] = Basket::count();
            $res['stations_rows'] = ScaleStation::count();
            $res['weighings_today'] = Weighing::whereDate('weighed_at', today())->count();
        } catch (\Throwable $e) {
            $res['count_error'] = $e->getMessage();
        }

        return json_encode($res);
    }

    private function toolCheckDeploy(): string
    {
        $res = [];
        $res['route_cache_ada'] = is_file(base_path('bootstrap/cache/routes-v7.php'))
            ? 'YA (BAHAYA: route basi bisa bikin 500 setelah deploy!)'
            : 'tidak ada (bagus)';
        $res['config_cache_ada'] = is_file(base_path('bootstrap/cache/config.php')) ? 'ya' : 'tidak';
        $res['routes_web_umur_detik'] = time() - @filemtime(base_path('routes/web.php'));
        $res['controller_umur_detik'] = time() - @filemtime(app_path('Http/Controllers/api/WeighingController.php'));

        return json_encode($res);
    }

    private function toolCheckBridge(string $station): string
    {
        $code = strtoupper(trim($station) ?: 'POS-01');
        $status = Cache::get('bridge_status:'.$code);
        if (! $status && $code === 'POS-01') {
            $status = Cache::get('bridge_status');
        }
        if (! $status) {
            return "Bridge {$code}: BELUM PERNAH lapor ke server.";
        }
        $age = (int) abs(now()->diffInSeconds(Carbon::parse($status['at'])));

        return json_encode([
            'lapor_terakhir_detik_lalu' => $age,
            'segar' => $age <= 30,
            'hostname' => $status['hostname'] ?? null,
            'com_port' => $status['com_port'] ?? null,
            'ports_terlihat' => $status['ports'] ?? [],
            'installer_terlihat' => $status['installers'] ?? [],
        ]);
    }

    /**
     * Minta vonis ke AI via 9router (OpenAI-compatible).
     *
     * Mode "agen baca-sendiri": SEBELUM tanya, server menjalankan SEMUA
     * tool read-only (log, config, DB, deploy, bridge) dan hasilnya
     * ditempel ke pesan. AI memeriksa bukti segar setiap vonis tanpa
     * perlu bolak-balik panggil tool (provider menolak format tools).
     */
    /**
     * Kumpulkan bukti segar dari server (semua tool read-only).
     *
     * @return array{0: array, 1: array}
     */
    private function collectEvidence(array $payload): array
    {
        $evidence = [];
        $trace = [];
        foreach ([
            'read_app_log' => [],
            'check_config' => [],
            'check_database' => [],
            'check_deploy' => [],
            'check_bridge' => ['station' => $payload['station'] ?? 'POS-01'],
        ] as $tool => $args) {
            $evidence[$tool] = $this->runAiTool($tool, $args);
            $trace[] = $tool;
        }

        return [$evidence, $trace];
    }

    /**
     * Ringkasan bukti yang enak dibaca manusia (disimpan + ditampilkan).
     */
    private function summarizeEvidence(array $evidence): array
    {
        $summary = [];
        foreach ($evidence as $tool => $output) {
            $summary[$tool] = substr((string) $output, 0, 600);
        }

        return $summary;
    }

    private function askAi(array $payload, array $evidence = []): array
    {
        $fallback = [
            'verdict' => 'unknown',
            'title' => 'AI tidak bisa dihubungi',
            'explanation' => 'Pemeriksaan otomatis tetap jalan dari data mentah, tapi AI tidak menjawab. Cek koneksi server ke 9router.',
            'steps' => ['Ulangi CEK ERROR beberapa saat lagi.'],
        ];

        $base = rtrim((string) config('ai.nine_base_url'), '/');
        $key = (string) config('ai.nine_key');
        $model = (string) config('ai.nine_model', 'kr/claude-haiku-4.5');

        if ($base === '' || $key === '') {
            $fallback['explanation'] = 'Kunci AI belum dipasang di server (AI_9ROUTER_KEY kosong).';

            return $fallback;
        }

        $system = 'Kamu asisten diagnosa mesin timbangan ayam hidup (indikator Balanza BX1 via RS232/USB, bridge Python, aplikasi Laravel). '
            .'Berdasarkan DATA JSON di bawah, tentukan SATU sumber masalah: "scale" (mesin/kabel/driver/baud/parsing), '
            .'"laptop" (browser bukan Chrome, izin USB, kabel belum dipilih, jaringan lokal), atau "web" (server/API/setting). '
            .'ATURAN KERAS: dilarang mengarang URL, port, nama tombol, angka, atau perintah yang tidak ada di FAKTA atau DATA. '
            .'Kalau tidak yakin, tulis langkah verifikasi ("cek ..."), bukan vonis pasti. '
            .'Kalau payload menunjukkan aktivitas NOL di semua sisi, verdict HARUS "unknown" dan arahkan ke langkah mulai, bukan menyalahkan mesin. '
            .'FAKTA APLIKASI (satu-satunya yang boleh disebut): tombol browser bernama persis "HUBUNGKAN USB" dan "CEK ERROR"; '
            .'halaman diagnosa di "/bug-log"; endpoint API "/api/weighings/live", "/api/bridge/status"; '
            .'bridge Python dijalankan manual (file: bridge/reader.py) dengan argumen --api-url dan --station; '
            .'JANGAN tulis "localhost:5000" (tidak ada); URL server = host aplikasi ini sendiri, jangan asumsikan localhost:8000; '
            .'baud default 9600. '
            .'Telemetri USB yang tersedia: usb_connected (port kebuka), usb_lines (baris), usb_bytes (bytes), '
            .'usb_parse_ok vs usb_parse_fail, usb_baud, usb_port (vid/pid), weight_source (usb/bridge). '
            .'Aturan baca: (1) usb_connected hijau + lines 0 + bytes 0 = port kebuka tapi salah pilih COM atau kabel TX putus '
            .'(driver pasti ada, tanpa driver port tak bisa dibuka); (2) bytes naik tapi lines 0 = data numpuk tanpa newline, '
            .'BX1 belum mode continuous/print; (3) parse_ok 0 + parse_fail>0 = baud/format salah, suruh coba 4800/9600/19200 + hubungkan ulang; '
            .'(4) parse_ok>0 + raw 0.00 = NOL BENERAN (timbangan kosong/tare), BUKAN error koneksi — arahkan taruh beban known, jangan vonis scale rusak. '
            .'Kalau payload berisi "raw_sample" (contoh baris mentah timbangan): analisis formatnya di explanation '
            .'(sebutkan polanya dengan kata-kata), tulis regex JavaScript yang cocok di steps bila perlu, '
            .'dan JANGAN suruh operator mengubah apa pun dari regex itu sendiri. '
            .'Jawab HANYA JSON valid tanpa markdown: {"verdict": "scale|laptop|web|unknown", "title": "maks 8 kata", '
            .'"explanation": "2-3 kalimat Bahasa Indonesia untuk operator", "steps": ["maks 5 langkah konkret dan berurutan"]}.';

        // Bukti sudah dikumpulkan pemanggil via collectEvidence().
        $trace = array_keys($evidence);

        $userContent = 'DATA BROWSER+SERVER: '.json_encode($payload)
            ."\n\nHASIL PEMERIKSAAN SERVER (otomatis, barusan):\n".json_encode($evidence);

        try {
            $res = Http::timeout(120)
                ->withToken($key)
                ->post($base.'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'max_tokens' => 800,
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ]);

            if (! $res->successful()) {
                Log::warning('diagnose: 9router HTTP '.$res->status().' :: '.substr($res->body(), 0, 300));

                return $fallback + ['trace' => $trace];
            }

            [$text] = $this->extractReply($res->body());
            $parsed = $this->parseVerdict($text);
            if ($parsed !== null) {
                return $parsed + ['trace' => $trace];
            }

            Log::warning('diagnose: unparseable final, raw='.substr($res->body(), 0, 800));

            return $fallback + ['trace' => $trace];
        } catch (\Throwable $e) {
            report($e);

            return $fallback + ['trace' => $trace ?? []];
        }
    }

    /**
     * Ambil teks + tool_calls dari balasan (JSON polos atau SSE).
     *
     * @return array{0: string, 1: array}
     */
    private function extractReply(string $raw): array
    {
        $raw = trim($raw);

        if (! str_starts_with($raw, 'data:')) {
            $json = json_decode($raw, true);
            $message = $json['choices'][0]['message'] ?? [];
            $calls = [];
            foreach ((array) ($message['tool_calls'] ?? []) as $tc) {
                $args = json_decode((string) ($tc['function']['arguments'] ?? '{}'), true);
                $calls[] = [
                    'id' => (string) ($tc['id'] ?? uniqid('call_')),
                    'name' => (string) ($tc['function']['name'] ?? ''),
                    'args' => is_array($args) ? $args : [],
                ];
            }

            return [(string) ($message['content'] ?? ''), $calls];
        }

        $text = '';
        $frag = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $chunk = json_decode($payload, true);
            $delta = $chunk['choices'][0]['delta'] ?? [];
            if (isset($delta['content']) && is_string($delta['content'])) {
                $text .= $delta['content'];
            }
            foreach ((array) ($delta['tool_calls'] ?? []) as $tc) {
                $idx = (int) ($tc['index'] ?? 0);
                $frag[$idx]['id'] = $frag[$idx]['id'] ?? (string) ($tc['id'] ?? '');
                $frag[$idx]['name'] = ($frag[$idx]['name'] ?? '').(string) ($tc['function']['name'] ?? '');
                $frag[$idx]['args'] = ($frag[$idx]['args'] ?? '').(string) ($tc['function']['arguments'] ?? '');
            }
        }

        $calls = [];
        foreach ($frag as $i => $f) {
            if (($f['name'] ?? '') === '') {
                continue;
            }
            $args = json_decode($f['args'] ?? '{}', true);
            $calls[] = [
                'id' => $f['id'] !== '' ? $f['id'] : "call_{$i}",
                'name' => $f['name'],
                'args' => is_array($args) ? $args : [],
            ];
        }

        return [$text, $calls];
    }

    /**
     * Parse vonis JSON final. Null bila bukan JSON valid.
     */
    private function parseVerdict(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            return null;
        }

        $verdict = in_array($parsed['verdict'] ?? null, ['scale', 'laptop', 'web'], true)
            ? $parsed['verdict']
            : 'unknown';

        return [
            'verdict' => $verdict,
            'title' => substr((string) ($parsed['title'] ?? 'Hasil diagnosa'), 0, 120),
            'explanation' => (string) ($parsed['explanation'] ?? ''),
            'steps' => array_values(array_filter((array) ($parsed['steps'] ?? []), 'is_string')),
        ];
    }

    /**
     * Ambil teks jawaban AI, tahan banting: dukung JSON polos
     * maupun SSE stream (data: {...delta...}).
     */
    private function extractContent(string $raw): string
    {
        $raw = trim($raw);

        if (! str_starts_with($raw, 'data:')) {
            $json = json_decode($raw, true);

            return (string) ($json['choices'][0]['message']['content'] ?? '');
        }

        $out = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $chunk = json_decode($payload, true);
            $delta = $chunk['choices'][0]['delta']['content']
                ?? $chunk['choices'][0]['message']['content']
                ?? '';
            if (is_string($delta)) {
                $out .= $delta;
            }
        }

        return $out;
    }

    /**
     * Hitung baris ERROR hari ini di log (tanpa isi stack trace).
     */
    private function recentErrorCount(): int
    {
        try {
            $file = storage_path('logs/laravel.log');
            if (! is_file($file)) {
                return 0;
            }
            $today = now()->format('Y-m-d');
            $count = 0;
            $handle = fopen($file, 'r');
            if (! $handle) {
                return 0;
            }
            while (($line = fgets($handle)) !== false) {
                if (str_contains($line, $today) && str_contains($line, '.ERROR:')) {
                    $count++;
                }
            }
            fclose($handle);

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
