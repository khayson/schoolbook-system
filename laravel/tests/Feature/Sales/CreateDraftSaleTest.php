<?php

use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\UpdateDraftSale;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

test('create draft sale prices lines and stores snapshots', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create([
        'title' => 'English Reader',
        'selling_price' => 5000,
        'cost_price' => 3000,
    ]);

    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => $customer->id,
        'sale_date' => '2026-04-10',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ]);

    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and($sale->invoice_no)->toBeNull()
        ->and($sale->subtotal)->toBe(10000)
        ->and($sale->total)->toBe(10000)
        ->and($sale->balance_due)->toBe(0)
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->items->first()->product_title)->toBe('English Reader')
        ->and($sale->items->first()->unit_price)->toBe(5000)
        ->and($sale->items->first()->unit_cost)->toBe(3000)
        ->and($sale->items->first()->line_total)->toBe(10000);
});

test('update draft sale replaces lines and reprices', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $first = Product::factory()->create(['selling_price' => 1000]);
    $second = Product::factory()->create(['selling_price' => 2000]);

    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $first->id, 'quantity' => 1],
        ],
    ]);

    $updated = app(UpdateDraftSale::class)->execute($user, $sale, [
        'items' => [
            ['product_id' => $second->id, 'quantity' => 3],
        ],
    ]);

    expect($updated->items)->toHaveCount(1)
        ->and($updated->total)->toBe(6000)
        ->and($updated->items->first()->product_id)->toBe($second->id);
});

test('owner can create and update draft sales via api', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['selling_price' => 4000]);

    $create = $this->withToken($token)->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'sale_date' => '2026-05-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 5],
        ],
    ]);

    $create->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', 20000)
        ->assertJsonPath('data.items.0.quantity', 5);

    $saleId = $create->json('data.id');

    $this->withToken($token)
        ->putJson("/api/v1/sales/{$saleId}", [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.total', 8000);
});

test('non-draft sales cannot be updated', function () {
    $user = User::factory()->owner()->create();
    $sale = Sale::factory()->create([
        'status' => SaleStatus::Confirmed,
        'created_by' => $user->id,
    ]);

    expect(fn () => app(UpdateDraftSale::class)->execute($user, $sale, [
        'notes' => 'nope',
    ]))->toThrow(\DomainException::class);
});
