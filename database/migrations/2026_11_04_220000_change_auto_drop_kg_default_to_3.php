<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turunkan default auto_drop_kg dari 8 kg ke 3 kg.
     *
     * 8 kg terlalu besar untuk tukar keranjang cepat (berat tidak sempat
     * turun 8 kg) sehingga keranjang baru tidak ter-record. Dengan pelacakan
     * berat TERENDAH sejak record terakhir, 3 kg sudah cukup aman: keranjang
     * diam hanya goyang < 1 kg sehingga tidak akan salah re-arm.
     */
    public function up(): void
    {
        // Ubah default kolom untuk instalasi baru.
        if (Schema::hasColumn('settings', 'auto_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 3.00');
            // Update baris yang masih memakai default lama (belum diubah manual).
            DB::table('settings')->where('auto_drop_kg', 8.00)->update(['auto_drop_kg' => 3.00]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 8.00');
        }
    }
};
