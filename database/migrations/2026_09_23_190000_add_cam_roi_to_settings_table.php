<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // ROI display bacaan kamera (fraksi 0-1: x,y,w,h). Dipakai service
            // OCR + tombol BACA ANGKA kalau request tidak kirim roi eksplisit.
            $table->string('cam_roi', 50)->nullable()->after('cam_stream_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('cam_roi');
        });
    }
};
