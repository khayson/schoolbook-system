<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached sum of balance_due over the customer's confirmed sales, maintained under the
 * customer lock by every money action. Backfilled by the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('outstanding_balance')->default(0)->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('outstanding_balance');
        });
    }
};
