<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allocation ledger (spec 6.6). Rows are never updated or deleted. amount is signed:
 * positive applies money from a payment to a sale; a reversal row carries the negated
 * amount and points at the row it reverses. A sale's amount_paid is the sum of its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            // Unique: an allocation is reversed at most once (NULLs do not collide).
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('payment_allocations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sale_id');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
