<?php

namespace Database\Seeders;

use App\Models\ScaleStation;
use App\Models\Weighing;
use Illuminate\Database\Seeder;

class ScaleStationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pos1 = ScaleStation::firstOrCreate(
            ['code' => 'POS-01'],
            ['name' => 'Pos Timbang 01', 'com_port' => 'AUTO', 'baud_rate' => 9600, 'is_active' => true]
        );

        // Data lama (sebelum ada konsep pos) dianggap dari POS-01.
        Weighing::whereNull('scale_station_id')->update(['scale_station_id' => $pos1->id]);
    }
}
