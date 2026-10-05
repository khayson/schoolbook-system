<?php

namespace App\Services\Reference;

use App\Models\Level;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Free-text search over the approved list ("york maths basic 4").
 *
 * - A level in the query ("basic 4", "primary 4", "p4", "b4", "kg 2", "jhs 1") becomes a
 *   level filter: titles at that level, plus band-only titles whose band covers it.
 * - "maths"/"math" match "mathematics" (prefix search).
 * - MySQL/MariaDB: FULLTEXT (boolean mode, every word required, prefix match) over the
 *   normalized title, publisher and author, ordered by relevance. Words InnoDB does not
 *   index (shorter than 3 letters, stop-words) are matched with LIKE instead or dropped.
 * - SQLite (tests): LIKE for every word.
 */
class ReferenceBookSearch
{
    /** InnoDB's default stop-word list: requiring one of these in boolean mode finds nothing. */
    private const STOPWORDS = ['a', 'about', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from',
        'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where',
        'who', 'will', 'with', 'und', 'www'];

    private const COLUMNS = ['search_title', 'publisher_label', 'author'];

    public function apply(Builder $query, string $search): Builder
    {
        [$levelSlugs, $words] = self::parse($search);

        if ($levelSlugs !== []) {
            $levelIds = Level::query()->whereIn('slug', $levelSlugs)->pluck('id')->all();
            $bands = array_keys(array_filter(
                ReferenceMapper::BAND_LEVELS,
                fn (array $levels) => array_intersect($levels, $levelSlugs) !== [],
            ));
            $query->where(fn (Builder $q) => $q->whereIn('level_id', $levelIds)
                ->orWhere(fn (Builder $q) => $q->whereNull('level_id')->whereIn('band', $bands)));
        }

        $fulltext = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $indexed = $fulltext ? array_values(array_filter($words, fn (string $w) => strlen($w) >= 3)) : [];
        $boolean = implode(' ', array_map(fn (string $w) => '+'.$w.'*', $indexed));

        if ($boolean !== '') {
            $query->whereFullText(self::COLUMNS, $boolean, ['mode' => 'boolean']);
        }
        foreach (array_diff($words, $indexed) as $word) {
            $query->where(function (Builder $q) use ($word) {
                foreach (self::COLUMNS as $column) {
                    $q->orWhere($column, 'like', '%'.$word.'%');
                }
            });
        }

        if ($boolean !== '') {
            $query->orderByRaw('MATCH ('.implode(', ', self::COLUMNS).') AGAINST (? IN BOOLEAN MODE) DESC', [$boolean]);
        }

        return $query->orderBy('search_title')->orderBy('id');
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [level slugs, words]
     */
    public static function parse(string $search): array
    {
        $text = ' '.ReferenceMapper::searchTitle($search).' ';
        $levels = [];
        $patterns = [
            'primary-' => '/\s(?:basic|primary|class|b|p)\s?([1-6])(?=\s)/',
            'kg-' => '/\s(?:kg|kindergarten)\s?([12])(?=\s)/',
            'jhs-' => '/\sjhs\s?([1-3])(?=\s)/',
        ];
        foreach ($patterns as $prefix => $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $m) use ($prefix, &$levels): string {
                $levels[] = $prefix.$m[1];

                return ' ';
            }, $text);
        }

        $words = [];
        foreach (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = in_array($word, ['maths', 'math'], true) ? 'math' : $word;
            if (! in_array($word, self::STOPWORDS, true)) {
                $words[] = $word;
            }
        }

        return [array_values(array_unique($levels)), array_values(array_unique($words))];
    }
}
