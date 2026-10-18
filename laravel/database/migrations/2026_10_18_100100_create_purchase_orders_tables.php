<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no')->nullable()->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status'); // draft|ordered|partial|received|cancelled
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedBigInteger('unit_cost');
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
