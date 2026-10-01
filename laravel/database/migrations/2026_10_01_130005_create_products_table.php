<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('isbn')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('title');
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('edition')->nullable();
            $table->unsignedBigInteger('cost_price');
            $table->unsignedBigInteger('selling_price');
            $table->integer('reorder_level')->default(0);
            $table->integer('stock_on_hand')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['level_id', 'subject_id', 'language_id']);
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
