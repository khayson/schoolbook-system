<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * return_credit payments store the SRN as reference for audit but must not
 * participate in the duplicate-reference unique index (same rule as cash).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference_key']);
            $table->dropColumn('reference_key');
        });

        $expression = $mysql
            ? "case when `status` = 'valid' and `method` not in ('cash', 'return_credit') and `reference` is not null and trim(`reference`) <> ''
                then concat(`method`, ':', upper(replace(`reference`, ' ', ''))) end"
            : "case when status = 'valid' and method not in ('cash', 'return_credit') and reference is not null and trim(reference) <> ''
                then method || ':' || upper(replace(reference, ' ', '')) end";

        Schema::table('payments', function (Blueprint $table) use ($mysql, $expression) {
            $column = $table->string('reference_key', 140)->nullable();
            $mysql ? $column->storedAs($expression) : $column->virtualAs($expression);
            $table->unique('reference_key');
        });
    }

    public function down(): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference_key']);
            $table->dropColumn('reference_key');
        });

        $expression = $mysql
            ? "case when `status` = 'valid' and `method` <> 'cash' and `reference` is not null and trim(`reference`) <> ''
                then concat(`method`, ':', upper(replace(`reference`, ' ', ''))) end"
            : "case when status = 'valid' and method <> 'cash' and reference is not null and trim(reference) <> ''
                then method || ':' || upper(replace(reference, ' ', '')) end";

        Schema::table('payments', function (Blueprint $table) use ($mysql, $expression) {
            $column = $table->string('reference_key', 140)->nullable();
            $mysql ? $column->storedAs($expression) : $column->virtualAs($expression);
            $table->unique('reference_key');
        });
    }
};
