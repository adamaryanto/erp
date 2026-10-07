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
        Schema::create('delivery_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('basket_id')->nullable()->constrained();
            $table->string('vehicle_no');
            $table->date('delivered_at');
            $table->decimal('do_weight', 10, 2)->default(0);
            $table->integer('total_ekor')->default(0);
            $table->integer('dead_count')->default(0);
            $table->decimal('dead_kg', 8, 2)->default(0);
            $table->decimal('wet_kg', 8, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_sessions');
    }
};
