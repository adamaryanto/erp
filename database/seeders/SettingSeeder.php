<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::create([
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
        ]);
    }
}
