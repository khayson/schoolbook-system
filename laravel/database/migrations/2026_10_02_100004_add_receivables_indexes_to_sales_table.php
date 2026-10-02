<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receivables queries: open invoices by status/payment status/due date (aging), and a
 * customer's confirmed sales oldest-due first (auto-allocation, statements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['status', 'payment_status', 'due_date']);
            $table->index(['customer_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_status', 'due_date']);
            $table->dropIndex(['customer_id', 'status', 'due_date']);
        });
    }
};
