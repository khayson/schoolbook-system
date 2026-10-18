<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exchanges', function (Blueprint $table) {
            $table->string('settlement')->nullable()->after('notes');
        });

        Schema::table('stock_exchange_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_exchange_items', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });

        Schema::table('stock_exchanges', function (Blueprint $table) {
            $table->dropColumn('settlement');
        });
    }
};
