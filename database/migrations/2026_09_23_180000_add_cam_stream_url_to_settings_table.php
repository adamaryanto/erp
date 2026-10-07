<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // URL video live (MJPEG) khusus pratinjau. Snapshot URL tetap
            // dipakai service OCR (butuh JPEG, bukan stream).
            $table->string('cam_stream_url')->nullable()->after('cam_snapshot_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('cam_stream_url');
        });
    }
};
