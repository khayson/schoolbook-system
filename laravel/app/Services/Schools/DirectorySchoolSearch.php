<?php

namespace App\Services\Schools;

use Illuminate\Database\Eloquent\Builder;

/**
 * Directory search: every word must appear in the school's name, district or town
 * ("st peters kasoa", "methodist cape coast"). A few thousand rows per region, so LIKE is
 * enough on both MySQL and SQLite.
 */
final class DirectorySchoolSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $words = array_slice(array_filter(explode(' ', OsmSchoolMapper::searchName($search))), 0, 8);
        foreach ($words as $word) {
            $query->where(fn (Builder $q) => $q
                ->where('search_name', 'like', '%'.$word.'%')
                ->orWhere('district', 'like', '%'.$word.'%')
                ->orWhere('town', 'like', '%'.$word.'%'));
        }

        return $query;
    }
}
