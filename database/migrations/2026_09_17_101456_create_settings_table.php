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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('com_port')->default('COM3');
            $table->integer('baud_rate')->default(9600);
            $table->string('parity')->default('N');
            $table->string('parsing_regex')->default('/STX\s+([\d.]+)\s+kg/');
            $table->integer('sampling_hz')->default(10);
            $table->integer('duration_sec')->default(5);
            $table->integer('trim_percent')->default(10);
            $table->decimal('unstable_threshold_kg', 5, 2)->default(0.8);
            $table->boolean('simulate_mode')->default(false);
            $table->integer('simulate_noise_level')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
