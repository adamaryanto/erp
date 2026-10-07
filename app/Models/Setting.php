<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['com_port', 'baud_rate', 'parity', 'parsing_regex', 'sampling_hz', 'duration_sec', 'trim_percent', 'unstable_threshold_kg', 'simulate_mode', 'simulate_noise_level', 'auto_mode_enabled', 'auto_trigger_kg', 'auto_empty_kg', 'auto_duration_sec', 'beep_enabled', 'printer_name', 'receipt_paper', 'receipt_footer', 'company_name', 'company_address', 'company_phone', 'company_logo', 'cam_snapshot_url', 'cam_stream_url', 'cam_roi', 'ocr_min_conf', 'cam_auto_service'];
    protected function casts(): array
    {
        return [
            'simulate_mode' => 'boolean',
            'auto_mode_enabled' => 'boolean',
            'beep_enabled' => 'boolean',
            'cam_auto_service' => 'boolean',
        ];
    }

    /**
     * Get the first (and typically only) settings record.
     */
    public static function config(): self
    {
        return self::firstOrCreate([], [
            'com_port' => 'AUTO',
            'baud_rate' => 9600,
            'parity' => 'N',
            'parsing_regex' => '/STX\s+([\d.]+)\s+kg/',
            'sampling_hz' => 10,
            'duration_sec' => 5,
            'trim_percent' => 10,
            'unstable_threshold_kg' => 0.8,
            'simulate_mode' => false,
            'simulate_noise_level' => 1,
            'auto_mode_enabled' => true,
            'auto_trigger_kg' => 10,
            'auto_empty_kg' => 2,
            'auto_duration_sec' => 5,
            'beep_enabled' => true,
            'printer_name' => '',
            'receipt_paper' => '58mm',
            'receipt_footer' => 'Terima kasih',
            'company_name' => 'PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA',
            'company_address' => '',
            'company_phone' => '',
            'cam_snapshot_url' => '',
            'cam_stream_url' => '',
            'cam_roi' => '0.28,0.38,0.44,0.26',
            'ocr_min_conf' => 0.4,
            'cam_auto_service' => false,
        ]);
    }
}
