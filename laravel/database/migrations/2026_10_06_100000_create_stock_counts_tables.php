<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-take (spec 6.3, amended Phase 3). An item's system_qty, variance and
 * baseline_movement_id are recorded when its counted quantity is entered; unit_cost is
 * the cost price when the count is applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('status')->default('open');
            $table->json('filters')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('system_qty')->nullable();
            $table->integer('counted_qty')->nullable();
            $table->integer('variance')->nullable();
            $table->foreignId('baseline_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->dateTime('counted_at')->nullable();
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
