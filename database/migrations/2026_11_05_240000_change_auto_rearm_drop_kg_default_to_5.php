<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turunkan default auto_rearm_drop_kg ke 5 kg.
     *
     * Di lapangan, tukar keranjang bisa sangat cepat: angka tidak sempat
     * menyentuh 0 dan turunnya hanya 5-10 kg. Default 10 kg membuat
     * auto loop tidak pernah re-arm. 5 kg lebih sesuai.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_rearm_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 5.00');
            DB::table('settings')->where('auto_rearm_drop_kg', 10.00)->update(['auto_rearm_drop_kg' => 5.00]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'auto_rearm_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_rearm_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 10.00');
        }
    }
};
