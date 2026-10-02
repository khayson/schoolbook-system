<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only, idempotent: sets outstanding_balance from confirmed sales. Kept separate
 * from the column migration so it can be re-run (and tested) on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->update([
            'outstanding_balance' => DB::raw(
                "(select coalesce(sum(sales.balance_due), 0) from sales
                  where sales.customer_id = customers.id and sales.status = 'confirmed')"
            ),
        ]);
    }

    public function down(): void
    {
        // Nothing to undo: the column migration's down() drops the column.
    }
};
