<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            // Tipe ukuran keranjang (Kecil/Sedang/Besar). Tare tetap per
            // keranjang (tare_default_kg); ukuran tampil otomatis di dropdown
            // form timbang supaya operator tidak salah pilih.
            $table->string('size', 20)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
