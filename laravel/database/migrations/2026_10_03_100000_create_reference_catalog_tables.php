<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved (NaCCA) reference catalog: editions, the live list, the staging area an
 * import lands in before review, and publisher spelling aliases. See docs/reference-catalog.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_editions', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('source_url')->nullable();
            $table->char('file_sha256', 64);
            $table->date('published_at')->nullable();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'active', 'superseded', 'discarded'])->default('draft');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_unchanged')->default(0);
            $table->unsignedInteger('rows_changed')->default(0);
            $table->unsignedInteger('rows_removed')->default(0);
            $table->unsignedInteger('rows_with_issues')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('skipped')->nullable();
            $table->json('stated_counts')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index('file_sha256');
        });

        Schema::create('reference_books', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['textbook', 'subject_supplement', 'reader', 'guidance', 'elearning']);
            $table->string('source_serial', 20)->nullable();
            $table->string('title', 500);
            $table->string('search_title', 500);
            $table->string('level_label', 50)->nullable();
            $table->foreignId('level_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('band', 20)->nullable();
            $table->string('subject_label')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('author', 300)->nullable();
            $table->string('publisher_label');
            $table->foreignId('publisher_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('confidence', ['high', 'low'])->default('high');
            $table->string('isbn', 20)->nullable();
            $table->enum('status', ['approved', 'withdrawn'])->default('approved');
            $table->foreignId('first_seen_edition_id')->constrained('reference_editions')->restrictOnDelete();
            $table->foreignId('last_seen_edition_id')->constrained('reference_editions')->restrictOnDelete();
            $table->char('natural_key', 40)->unique();
            $table->timestamps();
            $table->index(['status', 'level_id', 'subject_id']);
            $table->index(['category', 'status']);
            $table->index('publisher_id');
        });

        Schema::create('reference_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_edition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page')->nullable();
            $table->unsignedInteger('position');
            $table->text('raw_text')->nullable();
            $table->enum('category', ['textbook', 'subject_supplement', 'reader', 'guidance', 'elearning']);
            $table->string('source_serial', 20)->nullable();
            $table->string('title', 500);
            $table->string('level_label', 50)->nullable();
            $table->foreignId('level_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('band', 20)->nullable();
            $table->string('subject_label')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('author', 300)->nullable();
            $table->string('publisher_label');
            $table->foreignId('publisher_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('confidence', ['high', 'low'])->default('high');
            // As printed, kept when the review merges it into another spelling (becomes an alias).
            $table->string('printed_publisher')->nullable();
            $table->char('natural_key', 40);
            $table->json('issues')->nullable();
            $table->enum('action', ['new', 'unchanged', 'changed', 'removed']);
            $table->json('changes')->nullable();
            $table->foreignId('reference_book_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('resolved')->default(false);
            $table->boolean('excluded')->default(false);
            $table->timestamps();
            $table->index(['reference_edition_id', 'action']);
            $table->index(['reference_edition_id', 'resolved']);
            $table->index(['reference_edition_id', 'natural_key']);
        });

        Schema::create('publisher_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias');
            $table->string('normalized_alias')->unique();
            $table->foreignId('publisher_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('reference_book_id')->nullable()->after('publisher_id')
                ->constrained()->restrictOnDelete();
            $table->string('variant_label', 100)->nullable()->after('reference_book_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_book_id');
            $table->dropColumn('variant_label');
        });
        Schema::dropIfExists('publisher_aliases');
        Schema::dropIfExists('reference_import_rows');
        Schema::dropIfExists('reference_books');
        Schema::dropIfExists('reference_editions');
    }
};
