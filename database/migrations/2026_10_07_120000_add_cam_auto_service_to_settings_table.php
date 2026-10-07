<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // AUTO service OCR: kalau nyala, halaman timbang-cam otomatis
            // nyalakan lagi service yang mati (selama halaman kebuka).
            $table->boolean('cam_auto_service')->default(false)->after('ocr_min_conf');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('cam_auto_service');
        });
    }
};
