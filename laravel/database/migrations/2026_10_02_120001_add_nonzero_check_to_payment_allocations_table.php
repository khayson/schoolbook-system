<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A zero-amount ledger row carries no meaning and would hide mistakes. MySQL/MariaDB
 * only: SQLite cannot add a CHECK to an existing table (the invariants cover it there).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_nonzero CHECK (amount <> 0)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payment_allocations DROP CHECK payment_allocations_amount_nonzero');
        }
    }
};
