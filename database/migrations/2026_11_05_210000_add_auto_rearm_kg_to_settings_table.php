<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-arm auto loop HANYA saat berat di bawah auto_rearm_kg.
     *
     * Sebelumnya re-arm bisa terjadi saat berat masih di sekitar keranjang
     * (mis. turun 5 kg sesaat / noise) sehingga terasa auto loop terus.
     * Sekarang setelah record, loop baru siap record lagi setelah berat
     * sempat turun ke bawah threshold ini (keranjang ditukar/kosong).
     * Default 20 kg: keranjang kosong (tare ~7 kg) dan keranjang dengan
     * ayam sedikit otomatis re-arm.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'auto_rearm_kg')) {
            Schema::table('settings', function ($table) {
                $table->decimal('auto_rearm_kg', 8, 2)->default(20.00)->after('auto_empty_kg');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_kg')) {
            Schema::table('settings', function ($table) {
                $table->dropColumn('auto_rearm_kg');
            });
        }
    }
};
