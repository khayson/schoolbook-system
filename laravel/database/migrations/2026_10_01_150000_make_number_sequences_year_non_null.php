<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('number_sequences')->whereNull('year')->update(['year' => 0]);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite test rebuilds from create migrations; change() is a no-op path.
            return;
        }

        DB::statement('ALTER TABLE number_sequences MODIFY year SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE number_sequences MODIFY year SMALLINT UNSIGNED NULL DEFAULT NULL');
        DB::table('number_sequences')->where('year', 0)->update(['year' => null]);
    }
};
