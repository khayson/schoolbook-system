<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('owner')->after('password');
            // FK added in Phase 2 when customers table exists.
            $table->unsignedBigInteger('customer_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('customer_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn(['role', 'customer_id', 'is_active']);
        });
    }
};
