<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Supplier::create([
            'name' => 'Barokah Jaya',
            'address' => 'Jl. Merdeka No. 123, Jakarta',
            'phone' => '0812-3456-7890',
        ]);

        Supplier::create([
            'name' => 'Ternak Sejahtera',
            'address' => 'Jl. Sukarno Hatta No. 456, Bandung',
            'phone' => '0813-9876-5432',
        ]);

        Supplier::create([
            'name' => 'Plasma Unggul',
            'address' => 'Jl. Ahmad Yani No. 789, Semarang',
            'phone' => '0814-5555-6666',
        ]);
    }
}
