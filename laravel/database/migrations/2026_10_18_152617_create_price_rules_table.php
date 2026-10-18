<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->unsignedInteger('value');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('customer_scope');
            $table->string('customer_scope_value')->nullable();
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();
            $table->unsignedInteger('min_order_total')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('stackable')->default(false);
            $table->string('floor_policy')->default('warn');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index(['type', 'scope_type']);
            $table->index(['priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
