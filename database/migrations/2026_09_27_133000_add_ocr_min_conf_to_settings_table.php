<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Batas keyakinan OCR (0-1). Bacaan di bawah ini dibuang.
            $table->float('ocr_min_conf')->default(0.4)->after('cam_roi');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('ocr_min_conf');
        });
    }
};
