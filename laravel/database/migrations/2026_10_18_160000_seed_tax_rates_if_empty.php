<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('tax_rates')->exists()) {
            return;
        }

        $now = now();
        DB::table('tax_rates')->insert([
            ['code' => 'vat', 'name' => 'VAT', 'rate_bps' => 1500, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'nhil', 'name' => 'NHIL', 'rate_bps' => 250, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'getfund', 'name' => 'GETFund', 'rate_bps' => 250, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        // Keep rates; they are reference data.
    }
};
