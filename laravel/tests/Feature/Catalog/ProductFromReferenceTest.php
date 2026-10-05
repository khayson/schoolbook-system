<?php

use App\Enums\StockMovementType;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\ReferenceBook;
use App\Models\StockMovement;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    Sanctum::actingAs($this->owner);
    publishFixtureList($this->owner);
});

function book(string $title): ReferenceBook
{
    return ReferenceBook::query()->where('title', $title)->firstOrFail();
}

test('a product created from an approved title is prefilled; explicit values win', function () {
    $book = book('Discover Science');

    $prefilled = $this->postJson('/api/v1/products', [
        'reference_book_id' => $book->id,
        'cost_price' => 3000,
        'selling_price' => 4500,
    ])->assertCreated();

    expect($prefilled->json('data'))->toMatchArray([
        'title' => 'Discover Science',
        'level_id' => $book->level_id,
        'subject_id' => $book->subject_id,
        'language_id' => $book->language_id,
        'publisher_id' => $book->publisher_id,
        'reference_book_id' => $book->id,
        'variant_label' => null,
        'stock_on_hand' => 0,
    ]);

    $maths = Subject::query()->where('slug', 'mathematics')->value('id');
    $explicit = $this->postJson('/api/v1/products', [
        'reference_book_id' => $book->id,
        'variant_label' => "Teacher's Guide",
        'subject_id' => $maths,
        'cost_price' => 3000,
        'selling_price' => 4500,
    ])->assertCreated();

    expect($explicit->json('data'))->toMatchArray([
        'title' => "Discover Science (Teacher's Guide)",
        'subject_id' => $maths,
        'level_id' => $book->level_id,
        'variant_label' => "Teacher's Guide",
    ]);

    $titled = $this->postJson('/api/v1/products', [
        'reference_book_id' => $book->id,
        'title' => 'My own title',
        'cost_price' => 1,
        'selling_price' => 2,
    ])->assertCreated();
    expect($titled->json('data.title'))->toBe('My own title')
        ->and(book('Discover Science')->products()->count())->toBe(3);
});

test('a title listed for a band, not one level, asks for the level', function () {
    $band = book('Twi Kasa Workbook 1'); // Lower Primary, no level, language unknown

    $this->postJson('/api/v1/products', ['reference_book_id' => $band->id, 'cost_price' => 1000, 'selling_price' => 1500])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'level_id' => 'This approved title is listed for a range of classes, not one level. Choose the level.',
            'language_id' => 'The language of this approved title is not known. Choose the language.',
        ]);

    $this->postJson('/api/v1/products', [
        'reference_book_id' => $band->id,
        'level_id' => Level::query()->where('slug', 'primary-1')->value('id'),
        'language_id' => Language::query()->where('code', 'tw-as')->value('id'),
        'cost_price' => 1000,
        'selling_price' => 1500,
    ])->assertCreated()->assertJsonPath('data.title', 'Twi Kasa Workbook 1');

    $this->postJson('/api/v1/products', ['reference_book_id' => 999999, 'cost_price' => 1, 'selling_price' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['reference_book_id', 'title', 'level_id']);
});

test('a missing SKU is generated from a sequence, skipping hand-typed ones', function () {
    $book = book('Discover Science');
    Product::factory()->create(['sku' => 'BK-000002']);

    $skus = collect(range(1, 3))->map(fn () => $this->postJson('/api/v1/products', [
        'reference_book_id' => $book->id, 'cost_price' => 1, 'selling_price' => 2,
    ])->assertCreated()->json('data.sku'))->all();

    expect($skus)->toBe(['BK-000001', 'BK-000003', 'BK-000004']);

    $this->postJson('/api/v1/products', ['reference_book_id' => $book->id, 'sku' => 'BK-000001', 'cost_price' => 1, 'selling_price' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('sku');
});

test('opening stock is received through the stock ledger at the cost price', function () {
    $response = $this->postJson('/api/v1/products', [
        'reference_book_id' => book('Discover Science')->id,
        'cost_price' => 2750,
        'selling_price' => 4000,
        'opening_stock' => 40,
    ])->assertCreated()->assertJsonPath('data.stock_on_hand', 40);

    $movement = StockMovement::query()->where('product_id', $response->json('data.id'))->sole();
    expect($movement->type)->toBe(StockMovementType::ReceiptIn)
        ->and($movement->quantity)->toBe(40)
        ->and($movement->unit_cost)->toBe(2750)
        ->and($movement->balance_after)->toBe(40);

    $this->postJson('/api/v1/products', ['reference_book_id' => book('Discover Science')->id, 'cost_price' => 1, 'selling_price' => 1, 'opening_stock' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors('opening_stock');
    $this->postJson('/api/v1/products', ['reference_book_id' => book('Discover Science')->id, 'cost_price' => 1, 'selling_price' => 1, 'stock_on_hand' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('stock_on_hand');
});

test('with an Idempotency-Key a retried create returns the same product; without one it still works', function () {
    $payload = ['reference_book_id' => book('Discover Science')->id, 'cost_price' => 3000, 'selling_price' => 4500, 'opening_stock' => 5];

    $first = $this->withHeader('Idempotency-Key', 'quick-create-1')->postJson('/api/v1/products', $payload)->assertCreated();
    $retry = $this->withHeader('Idempotency-Key', 'quick-create-1')->postJson('/api/v1/products', $payload)->assertCreated();

    expect($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(Product::query()->where('reference_book_id', $payload['reference_book_id'])->count())->toBe(1)
        ->and(Product::query()->find($first->json('data.id'))->stock_on_hand)->toBe(5);

    $this->withHeader('Idempotency-Key', 'quick-create-1')->postJson('/api/v1/products', [...$payload, 'cost_price' => 1])
        ->assertUnprocessable();

    $this->flushHeaders();
    $this->postJson('/api/v1/products', $payload)->assertCreated(); // Phase 1 form: no key
});

test('attach-code: an ISBN goes to isbn (and teaches the approved title), other codes to barcode', function () {
    $book = book('Discover Science');
    $product = stockedProduct(3, attributes: ['reference_book_id' => $book->id, 'isbn' => null, 'barcode' => null]);

    $this->postJson('/api/v1/products/attach-code', ['code' => '978-9988-0-1234-2', 'product_id' => $product->id])
        ->assertOk()
        ->assertJsonPath('data.isbn', '9789988012342')
        ->assertJsonPath('data.barcode', null);
    expect($book->fresh()->isbn)->toBe('9789988012342');

    // Same code again: no-op.
    $this->postJson('/api/v1/products/attach-code', ['code' => '9789988012342', 'product_id' => $product->id])->assertOk();

    // Not a valid ISBN (wrong check digit): a barcode.
    $this->postJson('/api/v1/products/attach-code', ['code' => '9789988012345', 'product_id' => $product->id])
        ->assertOk()
        ->assertJsonPath('data.barcode', '9789988012345');

    // Both slots now full: a third code is refused, not overwritten.
    $this->postJson('/api/v1/products/attach-code', ['code' => '6001234567890', 'product_id' => $product->id])
        ->assertConflict()
        ->assertJsonPath('code', 'code_slot_taken')
        ->assertJsonPath('details.field', 'barcode')
        ->assertJsonPath('details.current', '9789988012345');
});

test('attach-code refuses a code that belongs to another product, even a deleted one', function () {
    $a = stockedProduct(1, attributes: ['sku' => 'BK-777777', 'barcode' => '5012345678900']);
    $b = stockedProduct(1, attributes: ['barcode' => null, 'isbn' => null]);
    $gone = stockedProduct(0, attributes: ['barcode' => '4006381333931']);
    $gone->delete();

    $this->postJson('/api/v1/products/attach-code', ['code' => '5012345678900', 'product_id' => $b->id])
        ->assertConflict()
        ->assertJsonPath('code', 'duplicate_code')
        ->assertJsonPath('details', [
            'code' => '5012345678900', 'field' => 'barcode', 'product_id' => $a->id, 'sku' => 'BK-777777', 'title' => $a->title, 'deleted' => false,
        ]);

    $this->postJson('/api/v1/products/attach-code', ['code' => 'BK-777777', 'product_id' => $b->id])
        ->assertConflict()->assertJsonPath('details.field', 'sku');

    $this->postJson('/api/v1/products/attach-code', ['code' => '4006381333931', 'product_id' => $b->id])
        ->assertConflict()->assertJsonPath('details.deleted', true);

    expect($b->fresh()->barcode)->toBeNull();

    $this->postJson('/api/v1/products/attach-code', ['code' => 'x', 'product_id' => $b->id])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/products/attach-code', ['code' => '12345678', 'product_id' => $gone->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
});
