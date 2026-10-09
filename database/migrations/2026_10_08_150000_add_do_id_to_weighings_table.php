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
            if (! Schema::hasColumn('weighings', 'do_id')) {
                $table->foreignId('do_id')->nullable()->after('supplier_id')
                    ->constrained('delivery_orders')->nullOnDelete()
                    ->comment('DO yg dipilih saat nimbang (supplier diisi server dari DO)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weighings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('do_id');
        });
    }
};
