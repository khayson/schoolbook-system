<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments are financial records: no soft delete, never deleted. A void flips status
 * and reverses allocations through new payment_allocations rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->unsignedBigInteger('unallocated_amount')->default(0);
            $table->string('status')->default('valid');
            $table->text('void_reason')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status', 'paid_at']);
        });

        // SQLite cannot add a CHECK after the fact; MySQL 8.0.16+ / MariaDB 10.2+ enforce it.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_unallocated_within_amount CHECK (unallocated_amount <= amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
