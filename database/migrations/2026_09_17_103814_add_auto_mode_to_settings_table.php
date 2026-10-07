<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('auto_mode_enabled')->default(true)->after('simulate_noise_level');
            $table->decimal('auto_trigger_kg', 5, 2)->default(10)->after('auto_mode_enabled')->comment('Weight threshold to start sampling');
            $table->decimal('auto_empty_kg', 5, 2)->default(2)->after('auto_trigger_kg')->comment('Weight threshold to return to IDLE');
            $table->integer('auto_duration_sec')->default(5)->after('auto_empty_kg')->comment('Sampling duration');
            $table->boolean('beep_enabled')->default(true)->after('auto_duration_sec')->comment('Play beep sound on save');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['auto_mode_enabled', 'auto_trigger_kg', 'auto_empty_kg', 'auto_duration_sec', 'beep_enabled']);
        });
    }
};
