<?php

namespace App\Console\Commands;

use App\Models\AiDiagnosis;
use App\Models\ScaleStation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * TEMPORARY (gampang dihapus): watchdog diagnosa otomatis.
 *
 * Read-only + 1 insert ke ai_diagnoses. TIDAK ada SSH, TIDAK panggil AI
 * berbayar, TIDAK hapus/ubah data. Hasilnya muncul di halaman Bug Log.
 *
 * Cara pakai manual:  php artisan diagnose:watch
 * Cara hapus nanti:   hapus file ini + 3 baris schedule di routes/console.php
 *                     + blok "TEMPORARY" di WeighingController@storeLive.
 */
class DiagnoseWatch extends Command
{
    protected $signature = 'diagnose:watch
        {--station= : Cek 1 pos saja, misal POS-01}
        {--window=10 : Jendela histori menit yang diperiksa}
        {--cooldown=30 : Jangan bikin vonis sama < N menit terakhir}';

    protected $description = 'Cek otomatis timbangan beku / bridge diam per pos (temporary)';

    public function handle(): int
    {
        $window = max(2, (int) $this->option('window'));
        $cooldown = max(5, (int) $this->option('cooldown'));

        $stations = $this->option('station')
            ? collect([ScaleStation::resolve($this->option('station'))])
            : ScaleStation::where('is_active', true)->orderBy('code')->get();

        if ($stations->isEmpty()) {
            $this->warn('Tidak ada station aktif.');

            return self::SUCCESS;
        }

        foreach ($stations as $station) {
            $this->checkStation($station->code, $window, $cooldown);
        }

        return self::SUCCESS;
    }

    private function checkStation(string $code, int $window, int $cooldown): void
    {
        $code = strtoupper(trim($code));

        // Riwayat dari storeLive (bridge maupun forward USB).
        $hist = Cache::get('live_weight_history:'.$code, []);
        $cutoff = now()->subMinutes($window)->timestamp;
        $hist = array_values(array_filter($hist, function ($h) use ($cutoff) {
            return isset($h['w'], $h['at']) && strtotime($h['at']) >= $cutoff;
        }));

        $live = Cache::get('live_weight:'.$code);
        $liveAge = $live ? (int) abs(now()->diffInSeconds(Carbon::parse($live['at']))) : null;

        $status = Cache::get('bridge_status:'.$code);
        $bridgeAge = $status ? (int) abs(now()->diffInSeconds(Carbon::parse($status['at']))) : null;
        $bridgeFresh = $bridgeAge !== null && $bridgeAge <= 30;

        $weights = array_map(fn ($h) => (float) $h['w'], $hist);
        $span = 0;
        if (count($hist) >= 2) {
            $span = strtotime(end($hist)['at']) - strtotime($hist[0]['at']);
        }
        $frozen = count($weights) >= 15 && $span >= 120
            && (max($weights) - min($weights)) < 0.005;
        $lastW = $weights !== [] ? end($weights) : null;

        $verdict = null;
        $title = '';
        $explanation = '';
        $steps = [];

        if ($frozen && $lastW !== null && $lastW > 0.5) {
            // BX1 asli jitter ±0.01; beku persis = data tidak mengalir.
            $verdict = 'scale';
            $title = 'Angka timbangan beku di '.number_format($lastW, 2).' kg';
            $explanation = 'Server menerima '.count($weights).' sampel dalam '.gmdate('i:s', $span).' tapi nilainya persis sama. Kemungkinan kabel/load cell/BX1 berhenti ngirim, atau bridge mengulang nilai terakhir.';
            $steps = [
                'Lihat timbangan fisik: goyangkan keranjang, angka BX1 harus ikut berubah.',
                'Cek kabel RS232-to-USB + driver COM di PC pos (Device Manager → Ports).',
                'Pastikan BX1 mode continuous output, baud 9600 (lihat Pengaturan).',
                'Restart bridge: python bridge/reader.py --station '.$code,
                'Klik CEK ERROR di halaman timbang untuk vonis AI lengkap.',
            ];
        } elseif ($bridgeFresh && count($hist) === 0) {
            $verdict = 'scale';
            $title = 'Bridge hidup, timbangan diam';
            $explanation = 'Bridge '.$code.' melapor '.($bridgeAge ?? '?').' detik lalu ('.($status['com_port'] ?? '?').'), tapi tidak ada angka masuk '.$window.' menit terakhir. Kabel/complier oke, timbangan yang diam.';
            $steps = [
                'Pastikan BX1 nyala dan kabel RS232 nancep.',
                'Cek COM port di Pengaturan sama dengan Device Manager.',
                'Coba baud lain (4800/9600/19200) lalu hubungkan ulang.',
                'Lihat log bridge: ada baris masuk tapi parse gagal atau tidak?',
            ];
        } elseif ($bridgeAge === null || $bridgeAge > 120) {
            // Hanya vonis kalau memang sepi total (hindari spam pas idle malam).
            if (count($hist) === 0) {
                $verdict = 'laptop';
                $title = 'Bridge '.$code.' tidak melapor';
                $explanation = 'Tidak ada heartbeat bridge maupun angka dalam '.$window.' menit terakhir. Kemungkinan bridge belum dijalankan di PC pos atau jaringannya ke server putus.';
                $steps = [
                    'Di PC pos jalankan: python bridge/reader.py --station '.$code,
                    'Cek internet PC pos ke server ('.config('app.url').').',
                    'Kalau pakai USB langsung, klik HUBUNGKAN USB di Chrome (angka USB cuma lokal, tidak diteruskan ke laptop lain).',
                ];
            }
        }

        if ($verdict === null) {
            $this->info("[$code] OK/normal (sampel: ".count($hist).', bridge: '.($bridgeAge === null ? '-' : $bridgeAge.'s').').');

            return;
        }

        // Cooldown: jangan spam vonis yang sama.
        $recent = AiDiagnosis::where('station', $code)
            ->where('verdict', $verdict)
            ->where('created_at', '>=', now()->subMinutes($cooldown))
            ->exists();
        if ($recent) {
            $this->line("[$code] $verdict sudah ada < {$cooldown}m, skip.");

            return;
        }

        AiDiagnosis::create([
            'station' => $code,
            'verdict' => $verdict,
            'title' => '[AUTO] '.$title,
            'explanation' => $explanation,
            'steps' => $steps,
            'payload' => [
                'auto' => true,
                'source' => 'diagnose:watch',
                'samples' => count($hist),
                'span_seconds' => $span,
                'last_weight' => $lastW,
                'live_age_seconds' => $liveAge,
                'bridge_age_seconds' => $bridgeAge,
                'bridge_com' => $status['com_port'] ?? null,
            ],
        ]);

        $this->info("[$code] vonis $verdict dicatat: $title");
    }
}
