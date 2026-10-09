<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\CamReading;
use App\Models\ScaleStation;
use App\Models\Setting;
use App\Models\Weighing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class CamReadingController extends Controller
{
    /**
     * Simpan hasil baca kamera. Dipanggil service AI (cam_ocr.py),
     * BUKAN oleh browser operator.
     *
     * Sekaligus update live cache source=cam supaya halaman
     * /timbang-cam yang polling ?source=cam langsung jalan
     * tanpa ubah frontend.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'weighed_at' => 'nullable|date',
            'image_path' => 'nullable|string|max:500',
            'ocr_weight' => 'nullable|numeric|min:0|max:200',
            'ocr_conf' => 'nullable|numeric|min:0|max:1',
            'basket_pred' => 'nullable|string|max:20',
            'basket_conf' => 'nullable|numeric|min:0|max:1',
            'chicken_count' => 'nullable|integer|min:0|max:100',
            'chicken_conf' => 'nullable|numeric|min:0|max:1',
            'raw_payload' => 'nullable|array',
        ]);

        $station = ScaleStation::resolve($validated['station'] ?? null);
        // Daemon kirim weighed_at dalam UTC (ISO +00:00). Samakan ke zona
        // aplikasi SEBELUM simpan — kalau tidak, weighed_at tersimpan 7 jam
        // lebih awal: umur bacaan ngaco, filter "today" salah tanggal, dan
        // link mesin ±10 detik tidak pernah ketemu.
        $weighedAt = isset($validated['weighed_at'])
            ? now()->parse($validated['weighed_at'])->setTimezone(config('app.timezone'))
            : now();

        // OCR gagal (ocr_weight null): JANGAN masuk DB (biar tabel tidak
        // banjir ribuan baris gagal), tapi titipkan teks mentah ke cache
        // debug 60 detik supaya frontend bisa tampilkan "kamera lihat: ...".
        if (! isset($validated['ocr_weight'])) {
            Cache::put("cam_debug:{$station->code}", [
                'raw' => (string) ($validated['raw_payload']['ocr_raw'] ?? ''),
                'at' => now()->toIso8601String(),
            ], 60);

            return response()->json(['status' => 'debug', 'station' => $station->code], 200);
        }

        // 1) Live feed buat frontend (channel cam, pisah dari mesin).
        if (isset($validated['ocr_weight'])) {
            Cache::put("live_weight:{$station->code}:cam", [
                'weight' => (float) $validated['ocr_weight'],
                'at' => now()->toIso8601String(),
            ], 30);
        }

        // Nol = timbangan kosong: live cache diupdate (LED ikut 0 dalam
        // 2-3 dtk) tapi JANGAN masuk DB biar tidak banjir ribuan baris nol.
        if (! isset($validated['ocr_weight']) || (float) $validated['ocr_weight'] <= 0) {
            return response()->json(['status' => 'empty', 'station' => $station->code], 200);
        }

        // 2) Coba link ke timbangan mesin terdekat (±10 detik, station sama).
        //    Mesin = ground truth, cam = verifikasi. Kalau tidak ketemu ya
        //    tetap simpan (mesin_weighing_id null), nanti bisa di-link susulan.
        $mesin = Weighing::where('scale_station_id', $station->id)
            ->whereBetween('weighed_at', [$weighedAt->copy()->subSeconds(10), $weighedAt->copy()->addSeconds(10)])
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, weighed_at, ?))', [$weighedAt->toDateTimeString()])
            ->first();

        $selisihKg = null;
        $selisihPersen = null;
        if ($mesin && isset($validated['ocr_weight'])) {
            $selisihKg = round((float) $validated['ocr_weight'] - (float) $mesin->gross_avg, 2);
            $selisihPersen = $mesin->gross_avg > 0
                ? round($selisihKg / (float) $mesin->gross_avg * 100, 2)
                : 0;
        }

        $reading = CamReading::create([
            'scale_station_id' => $station->id,
            'mesin_weighing_id' => $mesin?->id,
            'weighed_at' => $weighedAt,
            'image_path' => $validated['image_path'] ?? null,
            'ocr_weight' => $validated['ocr_weight'] ?? null,
            'ocr_conf' => $validated['ocr_conf'] ?? null,
            'basket_pred' => $validated['basket_pred'] ?? null,
            'basket_conf' => $validated['basket_conf'] ?? null,
            'chicken_count' => $validated['chicken_count'] ?? null,
            'chicken_conf' => $validated['chicken_conf'] ?? null,
            'selisih_kg' => $selisihKg,
            'selisih_persen' => $selisihPersen,
            'raw_payload' => $validated['raw_payload'] ?? null,
        ]);

        return response()->json([
            'status' => 'saved',
            'station' => $station->code,
            'reading' => $reading->load(['station', 'mesinWeighing']),
            'linked_mesin_id' => $mesin?->id,
            'selisih_kg' => $selisihKg,
            'selisih_persen' => $selisihPersen,
        ], 201);
    }

    /**
     * Trigger OCR sekali dari form (/timbang-cam).
     * Server menjalankan bridge/cam_ocr.py --once ke snapshot URL
     * yang tersimpan di Pengaturan. Script-nya sendiri yang POST
     * hasilnya ke store() di atas, jadi live cache source=cam
     * otomatis terisi dan frontend tinggal polling.
     */
    public function capture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'roi' => 'nullable|string|max:50',
        ]);

        $settings = Setting::config();
        $snapshotUrl = trim((string) $settings->cam_snapshot_url);

        if ($snapshotUrl === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Snapshot URL belum diisi di Pengaturan.',
            ], 422);
        }

        $station = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');
        $roi = $validated['roi'] ?? Setting::config()->cam_roi ?: '0.28,0.38,0.44,0.26';

        // Python Laragon (yg ada easyocr+opencv), fallback ke python biasa.
        $candidates = [
            'C:\laragon\bin\python\python-3.10\python.exe',
            'python',
        ];
        $python = null;
        foreach ($candidates as $c) {
            if ($c === 'python' || is_file($c)) {
                $python = $c;
                break;
            }
        }

        $process = new Process([
            $python,
            base_path('bridge/cam_ocr.py'),
            '--snapshot-url', $snapshotUrl,
            '--station', $station,
            '--api-base', config('app.url'),
            '--roi', $roi,
            '--rotate', (string) ($settings->cam_rotate ?? 0),
            '--once',
            // --fast (recognizer saja, 2-4 dtk): --once full-detect bisa
            // 10-60 dtk CPU dan memblokir php artisan serve (single-thread)
            // → selama itu SEMUA request (STOP, polling) ikut macet.
            '--fast',
        ], null, [
            // Python butuh ini saat di-spawn dari web server (Apache/service
            // yg environment-nya minim). Tanpa SYSTEMROOT → fatal
            // "_Py_HashRandomization_Init: failed to get random numbers".
            // PYTHONHASHSEED=0 mematikan hash randomization sekalian.
            'SYSTEMROOT' => 'C:\Windows',
            'PYTHONHASHSEED' => '0',
            'PYTHONWARNINGS' => 'ignore',
            'PYTHONIOENCODING' => 'utf-8',
            'PATH' => (string) getenv('PATH'),
            'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
            'USERPROFILE' => (string) (getenv('USERPROFILE') ?: ''),
        ]);
        $process->setTimeout(180);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        // Ambil baris hasil: "OCR: 23.45 kg" atau "OCR gagal".
        $weight = null;
        if (preg_match('/OCR:\s*([\d.]+)\s*kg/i', $output, $m)) {
            $weight = (float) $m[1];
        }

        // Ambil baris terakhir yg relevan biar respons kecil.
        $lines = array_values(array_filter(explode("\n", $output)));
        $tail = implode("\n", array_slice($lines, -6));

        return response()->json([
            'status' => $process->isSuccessful() ? 'done' : 'error',
            'exit_code' => $process->getExitCode(),
            'ocr_weight' => $weight,
            'station' => $station,
            'snapshot_url' => $snapshotUrl,
            'log' => $tail,
        ], $process->isSuccessful() ? 200 : 500);
    }

    /**
     * Kunci cache status service per pos.
     */
    private function serviceKey(string $station): string
    {
        return 'cam_service:'.strtoupper($station);
    }

    private function stopFile(string $station): string
    {
        return storage_path('app/cam_stop_'.strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $station)).'.flag');
    }

    private function pidFile(string $station): string
    {
        return storage_path('app/cam_svc_'.strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $station)).'.pid');
    }

    private function pidFromFile(string $station): ?int
    {
        $f = $this->pidFile($station);
        if (! is_file($f)) {
            return null;
        }
        $pid = (int) trim((string) @file_get_contents($f));

        return $pid > 0 ? $pid : null;
    }

    private function pythonBin(): string
    {
        // pythonw = tanpa console window (cocok buat daemon dari web).
        $laragon = 'C:\laragon\bin\python\python-3.10\pythonw.exe';

        return is_file($laragon) ? $laragon : 'python';
    }

    /**
     * Cek service hidup via heartbeat file (disentuh daemon tiap loop).
     * tasklist.exe TIDAK dipakai — gagal dari konteks web.
     */
    private function heartbeatFile(string $station): string
    {
        return storage_path('app/cam_beat_'.strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $station)).'.flag');
    }

    private function heartbeatAlive(string $station, int $maxAge = 25): bool
    {
        $f = $this->heartbeatFile($station);
        if (! is_file($f)) {
            return false;
        }

        return (time() - (int) filemtime($f)) <= $maxAge;
    }

    private function statusFile(string $station): string
    {
        return storage_path('app/cam_stat_'.strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $station)).'.json');
    }

    /**
     * Baca diagnosa mandiri service (ditulis cam_ocr.py --status-file).
     * Kembalikan ['state','detail','updated_at'] atau null bila belum ada.
     */
    private function svcStatusRead(string $station): ?array
    {
        $f = $this->statusFile($station);
        if (! is_file($f)) {
            return null;
        }
        try {
            $d = json_decode((string) @file_get_contents($f), true);

            return is_array($d) ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * START service OCR kontinyu (daemon): model di-load SEKALI di awal,
     * tiap frame tinggal infer 2-3 detik (mode --fast, recognizer saja).
     * Kembali langsung (tidak nunggu), PID disimpan di cache.
     */
    public function startService(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'roi' => 'nullable|string|max:50',
            'interval' => 'nullable|numeric|min:0.1|max:10',
        ]);

        $station = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');
        $settings = Setting::config();
        $snapshotUrl = trim((string) $settings->cam_snapshot_url);

        if ($snapshotUrl === '') {
            return response()->json(['status' => 'error', 'message' => 'Snapshot URL belum diisi di Pengaturan.'], 422);
        }

        // Sudah jalan? Jangan dobel.
        if ($this->heartbeatAlive($station)) {
            $existing = Cache::get($this->serviceKey($station), []);
            $existing['pid'] = $this->pidFromFile($station);

            return response()->json(['status' => 'running', 'station' => $station] + $existing);
        }

        // Preflight: pastikan python + script ada SEBELUM spawn, biar gagalnya
        // jelas (JSON error) bukan fake "starting" yang 90 dtk kemudian mati.
        try {
            $check = new Process([$this->pythonBin(), '--version']);
            $check->setTimeout(15);
            $check->run();
            if (! $check->isSuccessful()) {
                return response()->json(['status' => 'error', 'message' => 'Python tidak bisa dijalankan ('.$this->pythonBin().'). Install Python + centang "Add to PATH", lalu: pip install requests opencv-python numpy easyocr'], 500);
            }
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Python tidak ditemukan: '.$e->getMessage()], 500);
        }
        if (! is_file(base_path('bridge/cam_ocr.py'))) {
            return response()->json(['status' => 'error', 'message' => 'File bridge/cam_ocr.py tidak ditemukan.'], 500);
        }

        $roi = $validated['roi'] ?? Setting::config()->cam_roi ?: '0.28,0.38,0.44,0.26';
        $interval = (string) ($validated['interval'] ?? 2);
        $safe = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $station));
        $stopFile = $this->stopFile($station);
        $pidFile = $this->pidFile($station);
        $beatFile = $this->heartbeatFile($station);
        $statFile = $this->statusFile($station);
        $logFile = storage_path('app/cam_log_'.$safe.'.txt');
        @unlink($stopFile);
        @unlink($pidFile);
        @unlink($beatFile);
        @unlink($statFile);

        // Spawn: "start /B" (detach, kembali langsung). Quoting diserahkan ke
        // Symfony (jangan dirakit manual — itu sumber popup kemarin).
        // Tanpa PowerShell/WMI/VBS-Run/popen yg semuanya gagal dari service.
        // PID dilacak via --pid-file yg ditulis service-nya sendiri.
        // NOTE: title kosong ("") WAJIB sebagai arg pertama start.
        $cmd = array_merge(
            ['C:\Windows\System32\cmd.exe', '/c', 'start', '/B', '',
                $this->pythonBin(), base_path('bridge/cam_ocr.py')],
            ['--snapshot-url', $snapshotUrl,
                '--station', $station,
                '--api-base', (string) config('app.url'),
                '--roi', $roi,
                '--rotate', (string) ($settings->cam_rotate ?? 0),
                '--interval', $interval,
                '--min-conf', (string) ($settings->ocr_min_conf ?? 0.4),
                '--fast', '--no-save', '--quiet',
                '--stop-file', $stopFile,
                '--pid-file', $pidFile,
                '--heartbeat-file', $beatFile,
                '--status-file', $statFile,
                '--log-file', $logFile]
        );

        try {
            $proc = new Process(
                $cmd,
                null,
                [
                    'SYSTEMROOT' => 'C:\Windows',
                    'PYTHONHASHSEED' => '0',
            'PYTHONWARNINGS' => 'ignore',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PATH' => (string) getenv('PATH'),
                    'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
                    'USERPROFILE' => (string) (getenv('USERPROFILE') ?: ''),
                ]
            );
            $proc->setTimeout(30);
            $proc->run();
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Gagal spawn service: '.$e->getMessage()], 500);
        }

        // JANGAN tunggu heartbeat di sini (dulu: sleep sampai 60 dtk).
        // Di php artisan serve (single-thread), 1 request lama = SELURUH
        // server macet: polling antre, tombol STOP ikut "STOPPING…" padahal
        // tidak diklik. Cek kilat 2 dtk saja; frontend sudah polling status
        // tiap 5 dtk dan tahu state "starting" (PID baru, heartbeat belum ada).
        $pid = $this->pidFromFile($station);

        $info = [
            'pid' => $pid,
            'started_at' => now()->toIso8601String(),
            'snapshot_url' => $snapshotUrl,
            'roi' => $roi,
            'interval' => (float) $interval,
        ];
        Cache::put($this->serviceKey($station), $info, 86400);

        sleep(2);

        return response()->json([
            'status' => $this->heartbeatAlive($station) ? 'running' : 'starting',
            'station' => $station,
        ] + $info);
    }

    /**
     * STOP service: kibarkan stop-flag (loop keluar sendiri) + taskkill
     * sebagai pengaman biar langsung mati.
     */
    public function stopService(Request $request): JsonResponse
    {
        $validated = $request->validate(['station' => 'nullable|string|max:20']);
        $station = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');

        @file_put_contents($this->stopFile($station), now()->toIso8601String());

        $pid = $this->pidFromFile($station);
        if ($pid) {
            try {
                // Timeout pendek (8 dtk): taskkill cuma pengaman, stop-flag
                // sudah cukup. Jangan bikin tombol STOP nunggu lama.
                $proc = new Process(['C:\Windows\System32\taskkill.exe', '/F', '/PID', (string) $pid]);
                $proc->setTimeout(8);
                $proc->run();
            } catch (\Throwable $e) {
                // Abaikan, stop-flag sudah cukup (terbukti ampuh).
            }
            @unlink($this->pidFile($station));
        }
        @unlink($this->heartbeatFile($station));
        @unlink($this->statusFile($station));
        Cache::forget($this->serviceKey($station));

        return response()->json(['status' => 'stopped', 'station' => $station, 'pid' => $pid]);
    }

    /**
     * AUTO ROI: deteksi area display otomatis via EasyOCR full-frame.
     * Jalan sekali (±10-30 dtk), hasilnya DIKEMBALIKAN (tidak langsung
     * disimpan) supaya operator bisa TEST dulu di modal baru SIMPAN.
     */
    public function autoRoi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
        ]);

        $settings = Setting::config();
        $snapshotUrl = trim((string) $settings->cam_snapshot_url);

        if ($snapshotUrl === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Snapshot URL belum diisi di Pengaturan.',
            ], 422);
        }

        $station = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');

        $candidates = [
            'C:\laragon\bin\python\python-3.10\python.exe',
            'python',
        ];
        $python = null;
        foreach ($candidates as $c) {
            if ($c === 'python' || is_file($c)) {
                $python = $c;
                break;
            }
        }

        $process = new Process([
            $python,
            base_path('bridge/cam_ocr.py'),
            '--snapshot-url', $snapshotUrl,
            '--station', $station,
            '--rotate', (string) ($settings->cam_rotate ?? 0),
            '--auto-roi',
        ], null, [
            'SYSTEMROOT' => 'C:\Windows',
            'PYTHONHASHSEED' => '0',
            'PYTHONWARNINGS' => 'ignore',
            'PYTHONIOENCODING' => 'utf-8',
            'PATH' => (string) getenv('PATH'),
            'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
            'USERPROFILE' => (string) (getenv('USERPROFILE') ?: ''),
        ]);
        $process->setTimeout(180);
        set_time_limit(200);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $roi = null;
        $text = null;
        if (preg_match('/^ROI:\s*([0-9.,]+)/mi', $output, $m)) {
            $roi = trim($m[1]);
        }
        if (preg_match('/^TEXT:\s*(.+)$/mi', $output, $m)) {
            $text = trim($m[1]);
        }

        $lines = array_values(array_filter(explode("\n", $output)));
        $tail = implode("\n", array_slice($lines, -6));

        if (! $process->isSuccessful() || $roi === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Deteksi gagal. Pastikan kamera menghadap display berisi angka.',
                'station' => $station,
                'log' => $tail,
            ], 500);
        }

        return response()->json([
            'status' => 'done',
            'station' => $station,
            'roi' => $roi,
            'text' => $text,
            'log' => $tail,
        ]);
    }

    /**
     * KALIBRASI FULL-MANDIRI 1 klik: STOP service (biar RAM lega) → coba
     * putar 0/90/180/270 → arah terbaik menang → SIMPAN ROI + Putar ke
     * Pengaturan → START lagi bila sebelumnya jalan. Operator tinggal
     * geser kamera terus klik 1 tombol.
     */
    public function autoCalibrate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
        ]);

        $settings = Setting::config();
        $snapshotUrl = trim((string) $settings->cam_snapshot_url);

        if ($snapshotUrl === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Snapshot URL belum diisi di Pengaturan.',
            ], 422);
        }

        $station = strtoupper(trim($validated['station'] ?? 'POS-01') ?: 'POS-01');

        // Ingat kondisi sebelum kalibrasi biar dikembalikan seperti semula.
        $wasRunning = $this->heartbeatAlive($station);
        $prevInfo = Cache::get($this->serviceKey($station), []);
        $prevInterval = (float) ($prevInfo['interval'] ?? 0.2);

        if ($wasRunning) {
            $this->stopService(new Request(['station' => $station]));
            sleep(2); // kasih napas: proses lama mati + RAM kelepas
        }

        $candidates = [
            'C:\laragon\bin\python\python-3.10\python.exe',
            'python',
        ];
        $python = null;
        foreach ($candidates as $c) {
            if ($c === 'python' || is_file($c)) {
                $python = $c;
                break;
            }
        }

        $process = new Process([
            $python,
            base_path('bridge/cam_ocr.py'),
            '--snapshot-url', $snapshotUrl,
            '--station', $station,
            '--auto-roi',
            '--try-rotate',
        ], null, [
            'SYSTEMROOT' => 'C:\Windows',
            'PYTHONHASHSEED' => '0',
            'PYTHONWARNINGS' => 'ignore',
            'PYTHONIOENCODING' => 'utf-8',
            'PATH' => (string) getenv('PATH'),
            'TEMP' => (string) (getenv('TEMP') ?: sys_get_temp_dir()),
            'USERPROFILE' => (string) (getenv('USERPROFILE') ?: ''),
        ]);
        // 4 arah × (load model sekali + infer): bisa 2-4 menit.
        $process->setTimeout(420);
        set_time_limit(450);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $roi = null;
        $text = null;
        $rotate = null;
        if (preg_match('/^ROI:\s*([0-9.,]+)/mi', $output, $m)) {
            $roi = trim($m[1]);
        }
        if (preg_match('/^TEXT:\s*(.+)$/mi', $output, $m)) {
            $text = trim($m[1]);
        }
        if (preg_match('/^ROTATE:\s*(\d+)/mi', $output, $m)) {
            $rotate = (int) $m[1];
        }

        $lines = array_values(array_filter(explode("\n", $output)));
        $tail = implode("\n", array_slice($lines, -8));

        if (! $process->isSuccessful() || $roi === null || $rotate === null) {
            // Gagal: kembalikan service seperti semula biar tidak mati suri.
            if ($wasRunning) {
                $this->startService(new Request(['station' => $station, 'interval' => $prevInterval]));
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Deteksi gagal di semua arah. Pastikan kamera menghadap display berisi angka.',
                'station' => $station,
                'log' => $tail,
            ], 500);
        }

        // Menang → simpan Putar + ROI ke Pengaturan.
        $settings->update(['cam_rotate' => $rotate, 'cam_roi' => $roi]);

        $restarted = false;
        if ($wasRunning) {
            $resp = $this->startService(new Request(['station' => $station, 'interval' => $prevInterval]));
            $restarted = ($resp->getData(true)['status'] ?? '') !== 'error';
        }

        return response()->json([
            'status' => 'done',
            'station' => $station,
            'roi' => $roi,
            'rotate' => $rotate,
            'text' => $text,
            'was_running' => $wasRunning,
            'restarted' => $restarted,
            'log' => $tail,
        ]);
    }

    /**
     * Simpan ROI hasil kalibrasi modal (format "x,y,w,h" fraksi 0-1).
     */    public function saveRoi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'roi' => 'required|string|max:50',
        ]);

        $parts = array_map('floatval', explode(',', $validated['roi']));
        if (count($parts) !== 4 || $parts[2] <= 0 || $parts[3] <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Format ROI: x,y,w,h (contoh 0.28,0.38,0.44,0.26)'], 422);
        }

        $settings = Setting::config();
        $settings->update(['cam_roi' => $validated['roi']]);

        return response()->json(['status' => 'saved', 'roi' => $validated['roi']]);
    }

    /**
     * Baca flag AUTO service (tahan kalau kolom belum di-migrate: fallback cache).
     */
    private function camAutoEnabled(): bool
    {
        try {
            if (Schema::hasColumn('settings', 'cam_auto_service')) {
                return (bool) Setting::config()->cam_auto_service;
            }
        } catch (\Throwable $e) {
            // abaikan, pakai cache di bawah
        }

        return (bool) Cache::get('cam_auto_service', false);
    }

    private function camAutoSave(bool $enabled): bool
    {
        try {
            if (Schema::hasColumn('settings', 'cam_auto_service')) {
                Setting::config()->update(['cam_auto_service' => $enabled]);

                return (bool) Setting::config()->cam_auto_service;
            }
        } catch (\Throwable $e) {
            // abaikan, pakai cache di bawah
        }
        Cache::put('cam_auto_service', $enabled, 86400 * 30);

        return (bool) Cache::get('cam_auto_service', false);
    }

    /**
     * ON/OFF mode AUTO: halaman timbang-cam otomatis nyalakan lagi
     * service yang mati (selama halaman kebuka + polling jalan).
     */
    public function setAuto(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        return response()->json([
            'status' => 'ok',
            'cam_auto' => $this->camAutoSave((bool) $validated['enabled']),
        ]);
    }

    /**
     * Status service + umur bacaan terakhir (buat pill di form).
     */
    public function serviceStatus(Request $request): JsonResponse
    {
        $stationCode = strtoupper(trim($request->input('station', 'POS-01') ?: 'POS-01'));
        $info = Cache::get($this->serviceKey($stationCode), []);
        $pid = $this->pidFromFile($stationCode) ?? (int) ($info['pid'] ?? 0);
        // Hidup = heartbeat segar. "Starting" = PID baru (<90 dtk) tapi
        // heartbeat belum ada (lagi load model ±20-30 dtk).
        $alive = $this->heartbeatAlive($stationCode);
        $pidAge = null;
        $pf = $this->pidFile($stationCode);
        if (is_file($pf)) {
            $pidAge = time() - (int) filemtime($pf);
        }
        $starting = ! $alive && $pidAge !== null && $pidAge < 90;

        // Bacaan terakhir = detak jantung service (lebih akurat dari PID).
        $station = ScaleStation::where('code', $stationCode)->first();
        $last = $station
            ? CamReading::where('scale_station_id', $station->id)->orderByDesc('id')->first()
            : null;
        $lastAge = $last ? (int) abs(now()->diffInSeconds($last->weighed_at)) : null;

        return response()->json([
            'station' => $stationCode,
            'running' => $alive,
            'starting' => $starting,
            'pid' => $pid ?: null,
            'started_at' => $info['started_at'] ?? null,
            'last_weight' => $last?->ocr_weight !== null ? (float) $last->ocr_weight : null,
            'last_age_seconds' => $lastAge,
            // Segar = ada bacaan < 10 detik terakhir (ritme ~2-3 dtk + toleransi).
            'fresh' => $lastAge !== null && $lastAge <= 10,
            // ROI tersimpan (biar modal kalibrasi kebuka langsung isi nilai server).
            'roi' => Setting::config()->cam_roi,
            // Debug: teks mentah terakhir yg dilihat OCR (termasuk yg gagal).
            'last_raw' => Cache::get("cam_debug:{$stationCode}")['raw'] ?? null,
            // Diagnosa mandiri service (ditulis cam_ocr.py --status-file).
            'svc_state' => ($svc = $this->svcStatusRead($stationCode)) ? ($svc['state'] ?? null) : null,
            'svc_detail' => $svc['detail'] ?? null,
            'svc_updated_at' => $svc['updated_at'] ?? null,
            // Mode AUTO: halaman otomatis start lagi kalau service mati.
            'cam_auto' => $this->camAutoEnabled(),
        ]);
    }

    /**
     * List bacaan hari ini + ringkasan selisih (buat panel perbandingan).
     */
    public function today(Request $request): JsonResponse
    {
        $query = CamReading::whereDate('weighed_at', today())
            ->with(['station', 'mesinWeighing'])
            ->orderByDesc('weighed_at');

        if ($request->filled('station')) {
            $station = ScaleStation::where('code', strtoupper(trim($request->input('station'))))->first();
            $query->when($station, fn ($q) => $q->where('scale_station_id', $station->id));
        }

        $readings = $query->limit(100)->get();
        $linked = $readings->whereNotNull('mesin_weighing_id');

        return response()->json([
            'readings' => $readings,
            'total_count' => $readings->count(),
            'linked_count' => $linked->count(),
            // Rata-rata abs selisih (indikator kesehatan kamera vs mesin)
            'avg_abs_selisih_kg' => round($linked->avg(fn ($r) => abs((float) $r->selisih_kg)) ?? 0, 3),
            'max_abs_selisih_kg' => round($linked->max(fn ($r) => abs((float) $r->selisih_kg)) ?? 0, 2),
        ]);
    }
}
