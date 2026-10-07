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
        Schema::table('settings', function (Blueprint $table) {
            $table->string('printer_name')->default('')->after('beep_enabled');
            $table->string('receipt_paper')->default('58mm')->after('printer_name');
            $table->string('receipt_footer')->default('Terima kasih')->after('receipt_paper');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['printer_name', 'receipt_paper', 'receipt_footer']);
        });
    }
};
