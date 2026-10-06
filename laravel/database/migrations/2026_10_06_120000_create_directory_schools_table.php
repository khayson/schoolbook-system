<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School directory (docs/school-directory.md): schools in the regions the shop supplies,
 * imported from OpenStreetMap (ODbL), searched to add a school as a customer quickly.
 * Not customers themselves; customer_id links an entry once it has been added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_schools', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('source_ref', 40)->unique();
            $table->string('name');
            $table->string('search_name');
            $table->string('region');
            $table->string('district')->nullable();
            $table->string('town')->nullable();
            $table->string('phone')->nullable();
            $table->string('levels')->nullable();
            $table->string('ownership', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['region', 'district']);
            $table->index('search_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_schools');
    }
};
