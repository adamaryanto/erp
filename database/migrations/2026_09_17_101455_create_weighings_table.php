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
        Schema::create('weighings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('basket_id')->constrained();
            $table->foreignId('operator_id')->constrained('users');
            $table->string('vehicle_no');
            $table->dateTime('weighed_at');
            $table->decimal('gross_avg', 7, 2);
            $table->decimal('tare_kg', 5, 2);
            $table->decimal('net_kg', 7, 2);
            $table->decimal('min_kg', 7, 2);
            $table->decimal('max_kg', 7, 2);
            $table->decimal('stddev', 5, 3);
            $table->json('raw_samples');
            $table->boolean('is_manual_tare')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weighings');
    }
};
