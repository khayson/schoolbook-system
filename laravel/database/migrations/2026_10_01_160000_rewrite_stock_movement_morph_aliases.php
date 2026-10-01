<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        DB::table('stock_movements')
            ->where('reference_type', 'App\\Models\\GoodsReceipt')
            ->update(['reference_type' => 'goods_receipt']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        DB::table('stock_movements')
            ->where('reference_type', 'goods_receipt')
            ->update(['reference_type' => 'App\\Models\\GoodsReceipt']);
    }
};
