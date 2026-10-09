<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'auto_duration_sec')) {
            return;
        }
        // Integer -> desimal 1 digit supaya 0.5 dtk bisa disimpan.
        DB::statement('ALTER TABLE `settings` MODIFY `auto_duration_sec` DECIMAL(3,1) NOT NULL DEFAULT 5');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'auto_duration_sec')) {
            return;
        }
        DB::statement('ALTER TABLE `settings` MODIFY `auto_duration_sec` INT NOT NULL DEFAULT 5');
    }
};
