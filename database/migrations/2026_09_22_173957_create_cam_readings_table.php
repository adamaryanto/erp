<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hasil baca kamera (OCR angka + prediksi keranjang/ayam).
     * DIPISAH dari weighings (mesin = kebenaran bayar, cam = verifikasi).
     * Link ke mesin via mesin_weighing_id (dicocokkan by station + timestamp).
     */
    public function up(): void
    {
        Schema::create('cam_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mesin_weighing_id')->nullable()->constrained('weighings')->nullOnDelete();
            $table->dateTime('weighed_at');

            // Bukti visual (path relatif storage/app/public/cam/xxx.jpg)
            $table->string('image_path', 500)->nullable();

            // OCR angka display timbangan
            $table->decimal('ocr_weight', 7, 2)->nullable();
            $table->float('ocr_conf')->nullable();

            // Klasifikasi keranjang (nanti: QR/warna; sekarang: string bebas)
            $table->string('basket_pred', 20)->nullable();
            $table->float('basket_conf')->nullable();

            // Hitung ayam (masih eksperimental, jangan buat bayar)
            $table->integer('chicken_count')->nullable();
            $table->float('chicken_conf')->nullable();

            // Selisih vs mesin (diisi otomatis saat link ketemu)
            $table->decimal('selisih_kg', 7, 2)->nullable();
            $table->decimal('selisih_persen', 5, 2)->nullable();

            // Payload mentah buat debug (roi, model version, raw ocr text)
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['scale_station_id', 'weighed_at']);
            $table->index('mesin_weighing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cam_readings');
    }
};
