<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-arm auto loop: HANYA saat berat turun >= auto_rearm_drop_kg dari
     * keranjang terakhir.
     *
     * Alur fisik di pabrik (ditinjau dari video operator): keranjang
     * penuh ditaruh di timbangan, dicatat, lalu ditarik keluar dan
     * keranjang berikutnya langsung masuk. Keranjang lama selalu benar-
     * benar hilang dari scale sebelum keranjang baru duduk, sehingga berat
     * pasti turun drastis — 10 kg cukup aman untuk keranjang ayam (penuh
     * ~28-39 kg). Turun sampai 0 pun otomatis masuk kriteria ini, jadi
     * satu aturan saja sudah menangkap semua kasus tukar keranjang.
     *
     * auto_rearm_kg (empty-scale) dibuang; ini menggantikannya.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_kg')) {
            Schema::table('settings', function ($table) {
                $table->dropColumn('auto_rearm_kg');
            });
        }
        if (! Schema::hasColumn('settings', 'auto_rearm_drop_kg')) {
            Schema::table('settings', function ($table) {
                $table->decimal('auto_rearm_drop_kg', 8, 2)->default(10.00)->after('auto_empty_kg');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_drop_kg')) {
            Schema::table('settings', function ($table) {
                $table->dropColumn('auto_rearm_drop_kg');
            });
        }
        if (! Schema::hasColumn('settings', 'auto_rearm_kg')) {
            Schema::table('settings', function ($table) {
                $table->decimal('auto_rearm_kg', 8, 2)->default(20.00)->after('auto_empty_kg');
            });
        }
    }
};
