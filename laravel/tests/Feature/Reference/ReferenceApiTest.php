<?php

use App\Models\Publisher;
use App\Models\ReferenceBook;
use App\Models\User;
use App\Services\Reference\ReferenceBookSearch;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    Sanctum::actingAs($this->owner);
});

function titlesOf($response): array
{
    return collect($response->json('data'))->map(fn (array $b) => $b['title'].' / '.$b['level'])->all();
}

test('search: words, maths = mathematics, and level words as a level filter', function () {
    publishFixtureList($this->owner);

    expect(titlesOf($this->getJson('/api/v1/reference-books?search=sunrise maths basic 2')->assertOk()))
        ->toBe(['Sunrise Mathematics for Basic Schools / Primary 2']);

    // "p2" also covers band-only titles of that band (Lower Primary = P1-3)... only if they have no level.
    expect(titlesOf($this->getJson('/api/v1/reference-books?search=p2')))->toBe([
        'Fun with Fractions for Primary 2 / Primary 2',
        'Number Games Activity Book for Basic 2 / Primary 2',
        'Sunrise Mathematics for Basic Schools / Primary 2',
        'Twi Kasa Workbook 1 / Lower Primary',
    ]);

    // Publisher and author are searched too.
    expect(titlesOf($this->getJson('/api/v1/reference-books?search=owusu')))->toBe(['Number Games Activity Book for Basic 2 / Primary 2'])
        ->and(titlesOf($this->getJson('/api/v1/reference-books?search=kente educational')))->toBe(['Science Around Us / JHS 2'])
        ->and($this->getJson('/api/v1/reference-books?search=nothing like this')->json('data'))->toBe([]);
});

test('the search query is parsed into level filters and words', function (string $query, array $levels, array $words) {
    expect(ReferenceBookSearch::parse($query))->toBe([$levels, $words]);
})->with([
    ['York maths Basic 4', ['primary-4'], ['york', 'math']],
    ['p4 english', ['primary-4'], ['english']],
    ['Primary 4', ['primary-4'], []],
    ['KG2 numeracy', ['kg-2'], ['numeracy']],
    ['jhs 1 science for the schools', ['jhs-1'], ['science', 'schools']],
    ['Learner’s Book', [], ['learners', 'book']],
    ['Book 4', [], ['book', '4']],
    ['+maths -sunrise* (basic) "2"', ['primary-2'], ['math', 'sunrise']],
    ['+-*"()@~<>', [], []],
]);

test('filters: level, subject, category, publisher, and stocked yes/no with stock totals', function () {
    publishFixtureList($this->owner);
    $discover = ReferenceBook::query()->where('title', 'Discover Science')->firstOrFail();
    stockedProduct(12, attributes: ['reference_book_id' => $discover->id]);
    stockedProduct(3, attributes: ['reference_book_id' => $discover->id]);
    $deleted = stockedProduct(50, attributes: ['reference_book_id' => $discover->id]);
    $deleted->delete();

    $stocked = $this->getJson('/api/v1/reference-books?stocked=1')->assertOk();
    expect($stocked->json('data'))->toHaveCount(1)
        ->and($stocked->json('data.0'))->toMatchArray(['title' => 'Discover Science', 'products_count' => 2, 'stock_on_hand' => 15]);

    expect($this->getJson('/api/v1/reference-books?stocked=0')->json('meta.total'))->toBe(10)
        ->and($this->getJson('/api/v1/reference-books?category=reader')->json('data.0.title'))->toBe('The Clever Tortoise')
        ->and($this->getJson('/api/v1/reference-books?publisher_id='.$discover->publisher_id)->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/reference-books?subject_id='.$discover->subject_id)->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/reference-books?level_id='.$discover->level_id)->json('meta.total'))->toBe(1);

    $this->getJson('/api/v1/reference-books?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/v1/reference-books?category=comics')->assertUnprocessable();
});

test('withdrawn titles are hidden unless asked for', function () {
    publishFixtureList($this->owner);
    ReferenceBook::query()->where('title', 'The Clever Tortoise')->update(['status' => 'withdrawn']);

    expect($this->getJson('/api/v1/reference-books')->json('meta.total'))->toBe(10)
        ->and($this->getJson('/api/v1/reference-books?status=withdrawn')->json('data.0.title'))->toBe('The Clever Tortoise');
});

test('the snapshot is the whole live list, gzipped, with an ETag and 304 when unchanged', function () {
    $edition = publishFixtureList($this->owner);

    $plain = $this->get('/api/v1/reference-books/snapshot', ['Accept' => 'application/json'])->assertOk();
    $etag = $plain->headers->get('ETag');
    expect($etag)->toMatch('/^"[0-9a-f]{40}"$/')
        ->and($plain->json('data.edition'))->toBe(['id' => $edition->id, 'label' => 'Test list', 'published_at' => null])
        ->and($plain->json('data.count'))->toBe(11)
        ->and($plain->json('data.books.0'))->toHaveKeys(['id', 'category', 'title', 'search_title', 'level_id', 'level', 'band', 'subject', 'language', 'publisher', 'author', 'isbn']);

    $gzip = $this->get('/api/v1/reference-books/snapshot', ['Accept-Encoding' => 'gzip, deflate'])->assertOk()
        ->assertHeader('Content-Encoding', 'gzip');
    expect(json_decode(gzdecode($gzip->getContent()), true)['data']['count'])->toBe(11)
        ->and(strlen($gzip->getContent()))->toBeLessThan(strlen($plain->getContent()));

    $this->get('/api/v1/reference-books/snapshot', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
    expect($this->get('/api/v1/reference-books/snapshot', ['If-None-Match' => $etag])->getContent())->toBe('');

    // Selling a book does not change the snapshot...
    stockedProduct(5, attributes: ['reference_book_id' => ReferenceBook::query()->value('id')]);
    $this->get('/api/v1/reference-books/snapshot', ['If-None-Match' => $etag])->assertStatus(304);

    // ...a renamed publisher does, and so does the list itself.
    $this->travel(1)->seconds();
    Publisher::query()->first()->update(['name' => 'Renamed Press']);
    $this->get('/api/v1/reference-books/snapshot', ['If-None-Match' => $etag])->assertOk();
});

test('the active edition advertises the snapshot ETag; before any publish it is null', function () {
    $this->getJson('/api/v1/reference-editions/active')->assertOk()->assertExactJson(['data' => null]);

    $edition = publishFixtureList($this->owner);
    $etag = $this->get('/api/v1/reference-books/snapshot')->headers->get('ETag');

    $this->getJson('/api/v1/reference-editions/active')->assertOk()
        ->assertJsonPath('data.id', $edition->id)
        ->assertJsonPath('data.books_count', 11)
        ->assertJsonPath('data.snapshot_etag', $etag);
});

test('the approved list API is owner only', function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'school']));

    $this->getJson('/api/v1/reference-books')->assertForbidden();
    $this->getJson('/api/v1/reference-books/snapshot')->assertForbidden();
    $this->getJson('/api/v1/reference-editions/active')->assertForbidden();
});
