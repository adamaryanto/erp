<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat batch penghitungan ayam gantung (hasil dari kamera).
     */
    public function up(): void
    {
        Schema::create('hanging_counts', function (Blueprint $table) {
            $table->id();
            $table->string('station', 20)->default('POS-01');
            $table->unsignedInteger('count')->default(0);
            $table->string('direction', 20)->default('L2R');
            $table->string('note')->nullable();
            $table->dateTime('counted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hanging_counts');
    }
};
