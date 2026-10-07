<?php

namespace Database\Seeders;

use App\Models\LineCounter;
use Illuminate\Database\Seeder;

class LineCounterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $counters = [
            ['code' => 'GANTUNG-01', 'name' => 'Gantung Hidup Line 1', 'stage' => 'gantung'],
            ['code' => 'KARKAS-01', 'name' => 'Gantung Karkas Line 1', 'stage' => 'karkas'],
        ];

        foreach ($counters as $counter) {
            LineCounter::firstOrCreate(['code' => $counter['code']], $counter);
        }
    }
}
