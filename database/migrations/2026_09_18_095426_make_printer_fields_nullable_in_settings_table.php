<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Form boleh mengirim kosong (Laravel mengubah "" jadi null),
     * jadi kolomnya harus nullable.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE settings MODIFY printer_name VARCHAR(255) NULL');
        DB::statement('ALTER TABLE settings MODIFY receipt_footer VARCHAR(255) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereNull('printer_name')->update(['printer_name' => '']);
        DB::table('settings')->whereNull('receipt_footer')->update(['receipt_footer' => 'Terima kasih']);
        DB::statement('ALTER TABLE settings MODIFY printer_name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE settings MODIFY receipt_footer VARCHAR(255) NOT NULL');
    }
};
