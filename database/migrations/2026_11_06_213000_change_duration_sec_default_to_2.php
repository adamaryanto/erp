<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durasi sampling manual disamakan dengan mode auto (2 detik).
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'duration_sec')) {
            DB::statement('ALTER TABLE settings MODIFY duration_sec INT NOT NULL DEFAULT 2');
            DB::table('settings')->where('duration_sec', 5)->update(['duration_sec' => 2]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'duration_sec')) {
            DB::statement('ALTER TABLE settings MODIFY duration_sec INT NOT NULL DEFAULT 5');
        }
    }
};
