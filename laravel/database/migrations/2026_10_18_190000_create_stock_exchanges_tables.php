<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_no')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('counterparty_name');
            $table->string('status'); // draft|confirmed|void
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('status');
        });

        Schema::create('stock_exchange_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_exchange_id')->constrained()->cascadeOnDelete();
            $table->string('direction'); // in|out
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamps();

            $table->unique(
                ['stock_exchange_id', 'direction', 'product_id'],
                'stock_exchange_items_exchange_dir_product_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_exchange_items');
        Schema::dropIfExists('stock_exchanges');
    }
};
