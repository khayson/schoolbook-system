<?php

use App\Actions\Payments\RecordPayment;
use App\Actions\Reference\ReviewReferenceImport;
use App\Actions\Reference\StageReferenceImport;
use App\Actions\Sales\CreateDraftSale;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Models\Sale;
use App\Models\User;
use App\Services\MoneyInvariants;
use App\Services\Reference\ReferenceListParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReferenceListFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| MySQL group — real locking / sequences
|--------------------------------------------------------------------------
|
| Excluded from the default phpunit run. Execute with:
|   php artisan test --group=mysql
|
| Requires schoolbook_test (DB_TEST_DATABASE) with the same DB_* credentials.
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        config(['database.default' => 'mysql_testing']);
        $this->artisan('migrate:fresh');
    })
    ->group('mysql')
    ->in('Mysql');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Sales helpers
|--------------------------------------------------------------------------
*/

/**
 * Draft through the real action. $lines: list of [Product, quantity] or
 * [Product, quantity, override_unit_price, override_reason].
 *
 * @param  list<array{0: Product, 1: int, 2?: int, 3?: string}>  $lines
 */
function makeDraftSale(User $user, array $lines, ?Customer $customer = null, array $extra = []): Sale
{
    $customer ??= Customer::factory()->create();

    return app(CreateDraftSale::class)->execute($user, [
        'customer_id' => $customer->id,
        'items' => array_map(fn (array $line): array => array_filter([
            'product_id' => $line[0]->id,
            'quantity' => $line[1],
            'override_unit_price' => $line[2] ?? null,
            'override_reason' => $line[3] ?? null,
        ], fn ($value) => $value !== null), $lines),
        ...$extra,
    ]);
}

function stockedProduct(int $stock, int $price = 1000, int $cost = 600, array $attributes = []): Product
{
    $product = Product::factory()->create([
        'selling_price' => $price,
        'cost_price' => $cost,
        ...$attributes,
    ]);
    $product->stock_on_hand = $stock;
    $product->save();

    return $product;
}

/*
|--------------------------------------------------------------------------
| Money invariants
|--------------------------------------------------------------------------
*/

function assertMoneyInvariants(): void
{
    $violations = app(MoneyInvariants::class)->check();

    expect($violations)->toBe([], implode("\n", array_map('strval', $violations)));
}

/**
 * Records a payment through the real action. Cash by default; auto-allocates oldest
 * first unless $extra says otherwise (allocations, auto_allocate, method, reference...).
 */
function recordPayment(User $user, Customer $customer, int $amount, array $extra = []): Payment
{
    return app(RecordPayment::class)->execute($user, [
        'customer_id' => $customer->id,
        'amount' => $amount,
        'method' => 'cash',
        ...$extra,
    ]);
}

/*
|--------------------------------------------------------------------------
| Approved (reference) list helpers
|--------------------------------------------------------------------------
*/

function stageList(User $user, ?ReferenceListFixture $fixture = null, string $sha = 'sha-1', string $label = 'Test list'): ReferenceEdition
{
    $list = (new ReferenceListParser)->parse(($fixture ?? ReferenceListFixture::standard())->chunks());

    return app(StageReferenceImport::class)->stageParsed($user, $list, $label, str_pad($sha, 64, '0'));
}

function stagedRow(ReferenceEdition $edition, string $title, ?string $serial = null): ReferenceImportRow
{
    return $edition->importRows()->where('title', $title)
        ->when($serial !== null, fn ($q) => $q->where('source_serial', $serial))
        ->orderBy('position')->firstOrFail();
}

function issueCodes(ReferenceImportRow $row): array
{
    return array_column($row->issues ?? [], 'code');
}

/** Accept every row the owner could accept; exclude rows with errors. */
function reviewEverything(ReferenceEdition $edition): void
{
    $review = app(ReviewReferenceImport::class);
    foreach ($edition->importRows()->get() as $row) {
        $row->hasErrors() ? $review->exclude($row) : $review->accept($row);
    }
}

/** The fixture as a later edition: one title dropped, one added, one printed differently. */
function secondEditionFixture(): ReferenceListFixture
{
    return (new ReferenceListFixture)
        ->page()
        ->heading('3.0 LIST OF APPROVED TEXTBOOKS FROM KINDERGARTEN TO JHS', 134.8)
        ->heading('NUMERACY/MATHEMATICS (LEARNER BOOKS,TEACHER GUIDES)')
        ->textbookHeader()
        ->textbook('1', 'Sunrise Mathematics for Basic Schools', 'Basic 1', 'Sunrise Press Ltd')
        ->textbook('2', 'Sunrise Mathematics for Basic Schools', 'Basic 2', 'Sunrise Press Company Ltd')
        ->textbook('3', ['Lakeside Series Mathematics for Junior High', 'Schools'], 'JHS 1', ['Lakeside Publications and', 'Stationery Ltd'])
        ->heading('SCIENCE (LEARNER BOOKS,TEACHER GUIDES)')
        ->textbookHeader()
        ->textbook('1', 'Discover Science', 'Basic 4', 'Baobab Publishing')
        ->textbook('2', 'Ocean Science', 'Basic 5', 'Baobab Publishing')
        ->heading('4.0 – LIST OF APPROVED SUPPLEMENTARY MATERIALS', 166.3)
        ->heading('4.2 READERS (STORY BOOKS)')
        ->supplementHeader()
        ->supplement('1', 'The Clever Tortoise', 'Akwaaba Stories');
}
