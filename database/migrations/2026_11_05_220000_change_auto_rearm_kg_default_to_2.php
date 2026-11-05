<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sesuaikan default auto_rearm_kg ke 2 kg.
     *
     * Setelah rekaman sebuah keranjang, auto loop baru siap record lagi
     * saat scale BENAR-BENAR KOSONG. Alur fisik di pabrik: keranjang
     * ditarik keluar, scale balik ke 0.0, baru keranjang berikutnya
     * masuk. Dengan default 2 kg, keranjang yang sama TIDAK PERNAH
     * ter-record ganda.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_rearm_kg DECIMAL(8,2) NOT NULL DEFAULT 2.00');
            DB::table('settings')->where('auto_rearm_kg', 20.00)->update(['auto_rearm_kg' => 2.00]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_rearm_kg DECIMAL(8,2) NOT NULL DEFAULT 20.00');
        }
    }
};
