<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Re-arm auto loop hanya bila berat TURUN minimal sebesar ini
            // dari keranjang terakhir (keranjang benar-benar diangkat),
            // bukan sekadar turun di bawah trigger.
            $table->decimal('auto_drop_kg', 8, 2)->default(8.00)->after('auto_empty_kg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('auto_drop_kg');
        });
    }
};
