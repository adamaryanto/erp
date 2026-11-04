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
     * turun 8 kg) sehingga keranjang baru tidak ter-record.
     */
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_drop_kg')) {
            DB::statement('ALTER TABLE settings MODIFY auto_drop_kg DECIMAL(8,2) NOT NULL DEFAULT 3.00');
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
