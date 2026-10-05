<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Phase 3 reports. sales (status, sale_date) already exists (created
 * with the sales table), so it is not repeated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->index('product_id', 'sale_items_product_id_report_index');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['type', 'occurred_at']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['type', 'occurred_at']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_product_id_report_index');
        });
    }
};
