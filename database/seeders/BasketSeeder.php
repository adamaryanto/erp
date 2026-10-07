<?php

namespace Database\Seeders;

use App\Models\Basket;
use Illuminate\Database\Seeder;

class BasketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['code' => 'B01', 'tare_default_kg' => 2.8],
            ['code' => 'B02', 'tare_default_kg' => 2.9],
            ['code' => 'B03', 'tare_default_kg' => 2.7],
            // Tipe keranjang besar (tara kesepakatan, bisa diubah/hapus di /master).
            ['code' => 'K01', 'tare_default_kg' => 6.9],
            ['code' => 'K02', 'tare_default_kg' => 7.9],
        ];

        foreach ($types as $type) {
            Basket::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
