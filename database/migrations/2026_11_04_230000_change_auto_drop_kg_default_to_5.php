<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Naikkan default auto_drop_kg dari 3 kg ke 5 kg.
     *
     * Re-arm butuh berat turun minimal sebesar ini dari keranjang terakhir.
     * Nilai lebih besar = lebih aman dari record berulang (keranjang diam
     * tidak ke-record lagi), tapi tukar keranjang harus turun lebih dalam
     * supaya terdeteksi.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 5.00');
            DB::table('settings')->where('auto_drop_kg', 3.00)->update(['auto_drop_kg' => 5.00]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 3.00');
        }
    }
};
