<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search on the approved list: FULLTEXT on MySQL/MariaDB (SQLite tests use the LIKE
 * fallback in ReferenceBookSearch), and an ISBN index for scans once editions carry ISBNs.
 * ISBN is not unique here: uniqueness belongs to products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_books', function (Blueprint $table) {
            $table->index('isbn');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('reference_books', function (Blueprint $table) {
                $table->fullText(['search_title', 'publisher_label', 'author'], 'reference_books_search_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('reference_books', function (Blueprint $table) {
                $table->dropFullText('reference_books_search_fulltext');
            });
        }

        Schema::table('reference_books', function (Blueprint $table) {
            $table->dropIndex(['isbn']);
        });
    }
};
