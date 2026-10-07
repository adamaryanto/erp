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
        Schema::table('delivery_sessions', function (Blueprint $table) {
            $table->decimal('basket_deduction_manual', 8, 2)->nullable()->after('basket_id');
            $table->integer('wet_count')->nullable()->default(0)->after('wet_kg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_sessions', function (Blueprint $table) {
            $table->dropColumn(['basket_deduction_manual', 'wet_count']);
        });
    }
};
