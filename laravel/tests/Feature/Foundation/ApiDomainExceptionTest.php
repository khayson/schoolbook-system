<?php

use App\DTOs\Pricing\PricedOrder;
use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceChangedException;
use App\Exceptions\SaleNotEditableException;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/test-errors')->group(function () {
        Route::get('stock', fn () => throw new InsufficientStockException([[
            'product_id' => 7, 'sku' => 'ENG-1', 'title' => 'English 1', 'requested' => 5, 'available' => 2,
        ]]));
        Route::get('price', fn () => throw new PriceChangedException(new PricedOrder([], 1000, 0, 0, 1000)));
        Route::get('credit', fn () => throw new CreditLimitExceededException(50000, 45000, 10000));
        Route::get('plain-domain', fn () => throw new DomainException('Some internal rule'));
    });
});

test('insufficient stock renders 422 with a per-item list', function () {
    $this->getJson('/api/test-errors/stock')
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Insufficient stock for one or more products.',
            'code' => 'insufficient_stock',
            'errors' => [],
            'details' => ['items' => [[
                'product_id' => 7, 'sku' => 'ENG-1', 'title' => 'English 1', 'requested' => 5, 'available' => 2,
            ]]],
        ]);
});

test('price changed renders 409 with the new priced order', function () {
    $this->getJson('/api/test-errors/price')
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed')
        ->assertJsonPath('details.priced_order.total', 1000)
        ->assertJsonPath('details.priced_order.lines', []);
});

test('credit limit exceeded renders 409 naming the override flag', function () {
    $this->getJson('/api/test-errors/credit')
        ->assertStatus(409)
        ->assertJsonPath('code', 'credit_limit_exceeded')
        ->assertJsonPath('details.override_flag', 'override_credit_limit')
        ->assertJsonPath('details.projected_balance', 55000);
});

test('errors is an object, not a list, when empty', function () {
    $body = $this->getJson('/api/test-errors/stock')->getContent();

    expect($body)->toContain('"errors":{}');
});

test('a plain DomainException is no longer reported as insufficient_stock', function () {
    $response = $this->getJson('/api/test-errors/plain-domain');

    $response->assertStatus(500);
    expect($response->json('code'))->not->toBe('insufficient_stock');
});

test('sale not editable carries the sale status', function () {
    $user = User::factory()->owner()->create();
    $sale = Sale::factory()->create(['status' => 'void', 'created_by' => $user->id]);

    $e = new SaleNotEditableException($sale);

    expect($e->status())->toBe(409)
        ->and($e->errorCode())->toBe('sale_not_editable')
        ->and($e->details())->toBe(['sale_id' => $sale->id, 'status' => 'void', 'action' => 'update'])
        ->and($e->getMessage())->toBe('This sale is void and cannot be updated.');
});
