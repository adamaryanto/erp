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
        Schema::table('weighings', function (Blueprint $table) {
            $table->foreignId('scale_station_id')->nullable()->after('basket_id')->constrained('scale_stations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scale_station_id');
        });
    }
};
