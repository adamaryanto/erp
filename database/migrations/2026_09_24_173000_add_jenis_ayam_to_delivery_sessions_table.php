<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_sessions', function (Blueprint $table) {
            // Jenis ayam per mobil: biasa (default) / parent. Diisi admin,
            // timbangan jalan sendiri via cam.
            $table->string('jenis_ayam', 20)->default('biasa')->after('basket_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_sessions', function (Blueprint $table) {
            $table->dropColumn('jenis_ayam');
        });
    }
};
