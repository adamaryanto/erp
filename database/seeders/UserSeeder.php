<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@rpa.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Operator 1',
            'email' => 'operator1@rpa.test',
            'password' => bcrypt('password'),
            'role' => 'operator',
        ]);

        User::create([
            'name' => 'Operator 2',
            'email' => 'operator2@rpa.test',
            'password' => bcrypt('password'),
            'role' => 'operator',
        ]);
    }
}
