<?php

use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\UpdateDraftSale;
use App\DTOs\Pricing\PriceLineInput;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function ownerToken(): array
{
    $user = User::factory()->owner()->create();

    return [$user, $user->createToken('test')->plainTextToken];
}

// --- Duplicate lines -------------------------------------------------------

test('pricing service merges duplicate lines by product, override price and reason', function () {
    $a = Product::factory()->create(['selling_price' => 1000]);
    $b = Product::factory()->create(['selling_price' => 2000]);

    $priced = app(PricingService::class)->priceLines(null, [
        new PriceLineInput($a->id, 2),
        new PriceLineInput($b->id, 1),
        new PriceLineInput($a->id, 3),
        new PriceLineInput($a->id, 1, 800, 'Bulk'),
        new PriceLineInput($a->id, 4, 800, 'Bulk'),
        new PriceLineInput($a->id, 1, 800, 'Other reason'),
    ], Carbon::parse('2026-04-01'));

    expect($priced->lines)->toHaveCount(4)
        ->and($priced->lines[0]->productId)->toBe($a->id)
        ->and($priced->lines[0]->quantity)->toBe(5)
        ->and($priced->lines[0]->isPriceOverridden)->toBeFalse()
        ->and($priced->lines[1]->productId)->toBe($b->id)
        ->and($priced->lines[2]->quantity)->toBe(5)
        ->and($priced->lines[2]->unitPrice)->toBe(800)
        ->and($priced->lines[3]->quantity)->toBe(1)
        ->and($priced->lines[3]->overrideReason)->toBe('Other reason')
        ->and($priced->total)->toBe(5 * 1000 + 2000 + 5 * 800 + 800)
        ->and($priced->quantitiesByProduct())->toBe([$a->id => 11, $b->id => 1]);
});

test('reason without an override price does not split a merge', function () {
    $a = Product::factory()->create(['selling_price' => 1000]);

    $priced = app(PricingService::class)->priceLines(null, [
        ['product_id' => $a->id, 'quantity' => 1],
        ['product_id' => $a->id, 'quantity' => 2, 'override_unit_price' => null, 'override_reason' => 'ignored'],
    ], Carbon::parse('2026-04-01'));

    expect($priced->lines)->toHaveCount(1)
        ->and($priced->lines[0]->quantity)->toBe(3)
        ->and($priced->lines[0]->overrideReason)->toBeNull();
});

test('draft sale via api stores duplicate product lines as one item', function () {
    [, $token] = ownerToken();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['selling_price' => 1500]);

    $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 3],
        ],
    ])
        ->assertCreated()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.total', 7500);
});

// --- Validation: inactive / soft-deleted rows, override reason ---------------

dataset('unusable products', [
    'inactive' => [fn () => Product::factory()->create(['is_active' => false])],
    'soft-deleted' => [function () {
        $product = Product::factory()->create();
        $product->delete();

        return $product;
    }],
]);

dataset('unusable customers', [
    'inactive' => [fn () => Customer::factory()->create(['is_active' => false])],
    'soft-deleted' => [function () {
        $customer = Customer::factory()->create();
        $customer->delete();

        return $customer;
    }],
]);

test('creating a draft with an unusable product returns 422', function (Closure $makeProduct) {
    [, $token] = ownerToken();
    $customer = Customer::factory()->create();
    $product = $makeProduct();

    $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('items.0.product_id');

    expect(Sale::query()->count())->toBe(0);
})->with('unusable products');

test('pricing preview with an unusable product returns 422', function (Closure $makeProduct) {
    [, $token] = ownerToken();
    $product = $makeProduct();

    $this->withToken($token)->postJson('/api/v1/pricing/preview', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.product_id');
})->with('unusable products');

test('creating a draft for an unusable customer returns 422', function (Closure $makeCustomer) {
    [, $token] = ownerToken();
    $customer = $makeCustomer();
    $product = Product::factory()->create();

    $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');
})->with('unusable customers');

test('switching a draft to an unusable customer returns 422', function (Closure $makeCustomer) {
    [$user, $token] = ownerToken();
    $product = Product::factory()->create();
    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);

    $this->withToken($token)->putJson("/api/v1/sales/{$sale->id}", [
        'customer_id' => $makeCustomer()->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');
})->with('unusable customers');

test('override price without a reason returns 422', function (string $method, string $uri) {
    [$user, $token] = ownerToken();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['selling_price' => 1000]);

    if ($uri === 'update') {
        $sale = app(CreateDraftSale::class)->execute($user, [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $uri = "/api/v1/sales/{$sale->id}";
    }

    $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, $uri, [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'override_unit_price' => 500]],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('items.0.override_reason');
})->with([
    'create' => ['POST', '/api/v1/sales'],
    'update' => ['PUT', 'update'],
    'preview' => ['POST', '/api/v1/pricing/preview'],
]);

test('service-level checks throw 422-mapped exceptions, not 500s', function (array $line, string $field) {
    $product = Product::factory()->create(['selling_price' => 1000]);
    $inactive = Product::factory()->create(['is_active' => false]);
    $line = array_map(fn ($v) => match ($v) {
        'PRODUCT' => $product->id,
        'INACTIVE' => $inactive->id,
        default => $v,
    }, $line);

    try {
        app(PricingService::class)->priceLines(null, [$line], Carbon::parse('2026-04-01'));
        $this->fail('Expected InvalidInputException');
    } catch (InvalidInputException $e) {
        expect($e->status())->toBe(422)
            ->and($e->errorCode())->toBe('validation_failed')
            ->and($e->errors())->toHaveKey($field);
    }
})->with([
    'override without reason' => [['product_id' => 'PRODUCT', 'quantity' => 1, 'override_unit_price' => 500], 'items.0.override_reason'],
    'blank reason' => [['product_id' => 'PRODUCT', 'quantity' => 1, 'override_unit_price' => 500, 'override_reason' => '  '], 'items.0.override_reason'],
    'zero quantity' => [['product_id' => 'PRODUCT', 'quantity' => 0], 'items.0.quantity'],
    'unknown product' => [['product_id' => 999999, 'quantity' => 1], 'items.0.product_id'],
    'inactive product' => [['product_id' => 'INACTIVE', 'quantity' => 1], 'items.0.product_id'],
]);

test('draft action rejects an inactive customer with a 422-mapped exception', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['is_active' => false]);
    $product = Product::factory()->create();

    expect(fn () => app(CreateDraftSale::class)->execute($user, [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]))->toThrow(InvalidInputException::class);
});

// --- Source is server-side ---------------------------------------------------

test('staff api ignores a client-supplied source and stores staff', function () {
    [, $token] = ownerToken();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();

    $response = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'source' => 'portal',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.source', 'staff');

    expect(Sale::query()->find($response->json('data.id'))->source)->toBe(SaleSource::Staff);
});

// --- Not editable / authorization -----------------------------------------------

test('updating a confirmed sale via api returns 409 sale_not_editable', function () {
    [$user, $token] = ownerToken();
    $sale = Sale::factory()->create([
        'status' => SaleStatus::Confirmed,
        'created_by' => $user->id,
    ]);

    $this->withToken($token)->putJson("/api/v1/sales/{$sale->id}", ['notes' => 'nope'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_not_editable')
        ->assertJsonPath('details.status', 'confirmed');
});

test('non-owner gets 403 on sales and pricing endpoints', function (string $method, string $uri) {
    $owner = User::factory()->owner()->create();
    $school = User::factory()->school()->create();
    $token = $school->createToken('test')->plainTextToken;
    $product = Product::factory()->create();
    $sale = app(CreateDraftSale::class)->execute($owner, [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);

    $payload = [
        'customer_id' => $sale->customer_id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ];

    $this->withToken($token)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->json($method, str_replace('{sale}', (string) $sale->id, $uri), $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(Sale::query()->count())->toBe(1)
        ->and($sale->fresh()->items->first()->quantity)->toBe(1);
})->with([
    'index' => ['GET', '/api/v1/sales'],
    'store' => ['POST', '/api/v1/sales'],
    'show' => ['GET', '/api/v1/sales/{sale}'],
    'update' => ['PUT', '/api/v1/sales/{sale}'],
    'preview' => ['POST', '/api/v1/pricing/preview'],
]);

test('pricing preview accepts an explicit null customer', function () {
    [, $token] = ownerToken();
    $product = Product::factory()->create(['selling_price' => 1200]);

    $this->withToken($token)->postJson('/api/v1/pricing/preview', [
        'customer_id' => null,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])
        ->assertOk()
        ->assertJsonPath('data.total', 2400)
        ->assertJsonPath('data.lines.0.applied_rules', [])
        ->assertJsonPath('data.warnings', []);
});

// --- Sales are never deleted --------------------------------------------------------

test('sales cannot be deleted by policy or model', function () {
    $user = User::factory()->owner()->create();
    $sale = Sale::factory()->create(['created_by' => $user->id]);

    expect($user->can('delete', $sale))->toBeFalse();
    expect(fn () => $sale->delete())->toThrow(LogicException::class);
    expect(Sale::query()->whereKey($sale->id)->exists())->toBeTrue();
});

test('cached money fields on sales are not mass assignable', function () {
    $sale = new Sale(['amount_paid' => 500, 'balance_due' => 500, 'total' => 1000]);

    expect($sale->amount_paid)->toBeNull()
        ->and($sale->balance_due)->toBeNull()
        ->and($sale->total)->toBe(1000);
});

// --- Activity log ------------------------------------------------------------------

test('sale changes are activity-logged with the acting user', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['selling_price' => 1000]);

    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);

    app(UpdateDraftSale::class)->execute($user, $sale, [
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ]);

    $events = Activity::query()
        ->where('subject_type', $sale->getMorphClass())
        ->where('subject_id', $sale->id)
        ->orderBy('id')
        ->get();

    $updated = $events->firstWhere('event', 'updated');

    expect($events->pluck('event')->all())->toContain('created', 'updated')
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$user->id])
        ->and($updated->attribute_changes['old']['total'] ?? null)->toBe(1000)
        ->and($updated->attribute_changes['attributes']['total'] ?? null)->toBe(3000);
});

test('price overrides are logged once with reason and user', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['selling_price' => 1000]);

    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'override_unit_price' => 700,
            'override_reason' => 'Loyal school',
        ]],
    ]);

    // Editing notes rewrites items from the stored override; that must not log it again.
    app(UpdateDraftSale::class)->execute($user, $sale, ['notes' => 'Deliver Friday']);

    $overrides = Activity::query()
        ->where('subject_type', $sale->getMorphClass())
        ->where('subject_id', $sale->id)
        ->where('event', 'price_overridden')
        ->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->causer_id)->toBe($user->id)
        ->and($overrides->first()->properties['reason'])->toBe('Loyal school')
        ->and($overrides->first()->properties['base_price'])->toBe(1000)
        ->and($overrides->first()->properties['unit_price'])->toBe(700);

    // A new override price on the same draft is logged.
    app(UpdateDraftSale::class)->execute($user, $sale, [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'override_unit_price' => 600,
            'override_reason' => 'Loyal school',
        ]],
    ]);

    expect(Activity::query()->where('event', 'price_overridden')->count())->toBe(2);
});
