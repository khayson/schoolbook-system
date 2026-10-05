<?php

use App\Actions\Reports\CatalogCoverage;
use App\Models\ReferenceBook;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * The FULLTEXT path (InnoDB boolean mode, prefix match, 3-letter minimum, stop-words)
 * only exists on MySQL/MariaDB; SQLite tests use the LIKE fallback.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    Sanctum::actingAs($this->owner);
    publishFixtureList($this->owner);
});

function searchTitles(string $query): array
{
    return collect(test()->getJson('/api/v1/reference-books?search='.urlencode($query))->assertOk()->json('data'))
        ->map(fn (array $b) => $b['title'].' / '.$b['level'])->all();
}

test('FULLTEXT search on MySQL: prefixes, stop-words, short words and levels', function () {
    expect(searchTitles('sunrise maths basic 2'))->toBe(['Sunrise Mathematics for Basic Schools / Primary 2'])
        ->and(searchTitles('the clever tortoise'))->toBe(['The Clever Tortoise / '])                // "the" is an InnoDB stop-word; readers have no level
        ->and(searchTitles('scien around us'))->toBe(['Science Around Us / JHS 2'])                // prefix + 2-letter word via LIKE
        ->and(searchTitles('kente educational'))->toBe(['Science Around Us / JHS 2'])              // publisher
        ->and(searchTitles('owusu'))->toBe(['Number Games Activity Book for Basic 2 / Primary 2'])  // author
        ->and(searchTitles('lakeside jhs 1'))->toBe(['Lakeside Series Mathematics for Junior High Schools / JHS 1'])
        ->and(searchTitles('xylophone'))->toBe([]);

    $fulltext = collect(DB::select("SHOW INDEX FROM reference_books WHERE Index_type = 'FULLTEXT'"))->pluck('Column_name')->all();
    expect($fulltext)->toBe(['search_title', 'publisher_label', 'author']);
});

test('FULLTEXT operator characters typed by a user are neutralised, never an SQL error', function () {
    // +maths -sunrise* (basic) "2" would be operators in boolean mode; normalisation strips them.
    expect(searchTitles('+maths -sunrise* (basic) "2"'))->toBe(['Sunrise Mathematics for Basic Schools / Primary 2'])
        ->and(searchTitles('@discover ~science <>'))->toBe(['Discover Science / Primary 4']);

    // Nothing left after normalisation: the unfiltered list, not an error.
    test()->getJson('/api/v1/reference-books?search='.urlencode('+-*"()@~<>'))->assertOk()->assertJsonPath('meta.total', 11);
});

test('the coverage summary runs on MySQL with the same figures', function () {
    stockedProduct(5, attributes: ['reference_book_id' => ReferenceBook::query()->where('title', 'Discover Science')->value('id')]);

    expect(app(CatalogCoverage::class)->summary()['totals'])
        ->toBe(['approved' => 11, 'in_products' => 1, 'missing' => 10, 'not_on_list' => 0]);
});
