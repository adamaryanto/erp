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
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique()->comment('No. DO/Faktur pembelian');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->date('issued_at')->nullable()->comment('Tanggal DO diterbitkan');
            $table->decimal('target_kg', 10, 2)->default(0)->comment('Total kg dibeli (DO asal)');
            $table->integer('target_ekor')->default(0)->comment('Total ekor dibeli');
            $table->string('status', 10)->default('open')->comment('open = masih bisa ditempeli sesi truk');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('delivery_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_sessions', 'do_id')) {
                $table->foreignId('do_id')->nullable()->after('supplier_id')
                    ->constrained('delivery_orders')->nullOnDelete()
                    ->comment('DO yang dipakai sesi truk ini (boleh kosong = manual)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('do_id');
        });
        Schema::dropIfExists('delivery_orders');
    }
};
