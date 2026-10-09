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
            if (! Schema::hasColumn('settings', 'cam_rotate')) {
                $table->integer('cam_rotate')->default(0)->after('cam_roi')->comment('Putar frame kamera: 0/90/180/270 derajat');
            }
            // Kolom ini dipakai kode (Setting::$fillable + casts + firstOrCreate)
            // tapi belum pernah dibuatkan migrasinya — sekalian dilengkapi.
            if (! Schema::hasColumn('settings', 'cam_auto_service')) {
                $table->boolean('cam_auto_service')->default(false)->after('ocr_min_conf');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['cam_rotate', 'cam_auto_service']);
        });
    }
};
