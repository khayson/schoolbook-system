<?php

use App\Actions\Inventory\CancelStockExchange;
use App\Actions\Inventory\ConfirmStockExchange;
use App\Actions\Inventory\CreateStockExchange;
use App\Actions\Inventory\UpdateDraftStockExchange;
use App\Actions\Inventory\VoidStockExchange;
use App\Enums\SaleStatus;
use App\Enums\StockExchangeDirection;
use App\Enums\StockExchangeStatus;
use App\Enums\StockMovementType;
use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\StockExchangeStateException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockExchange;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-18 14:00:00');
    $this->owner = User::factory()->owner()->create();
    $this->customer = Customer::factory()->create([
        'name' => 'Dealer Ama',
        'credit_limit' => 5_000_000,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

test('confirm both directions creates sale invoice and goods receipt', function () {
    $taken = Product::factory()->create([
        'title' => 'Taken Book',
        'selling_price' => 2000,
        'cost_price' => 1000,
    ]);
    $taken->stock_on_hand = 0;
    $taken->save();

    $gave = Product::factory()->create([
        'title' => 'Gave Book',
        'selling_price' => 3000,
        'cost_price' => 1500,
    ]);
    $gave->stock_on_hand = 10;
    $gave->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Kojo Dealer',
        'settlement' => 'installment',
        'notes' => 'Market swap',
        'items' => [
            ['direction' => 'in', 'product_id' => $taken->id, 'quantity' => 4, 'unit_cost' => 1100],
            ['direction' => 'out', 'product_id' => $gave->id, 'quantity' => 2, 'unit_price' => 3000],
        ],
    ]);

    expect($exchange->status)->toBe(StockExchangeStatus::Draft)
        ->and($exchange->items)->toHaveCount(2)
        ->and($exchange->items->firstWhere(fn ($i) => $i->direction->value === 'out')->quantity)->toBe(2);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange);

    expect($confirmed->status)->toBe(StockExchangeStatus::Confirmed)
        ->and($confirmed->exchange_no)->toStartWith('EXC-2026-')
        ->and($confirmed->sale_id)->not->toBeNull()
        ->and($confirmed->goods_receipt_id)->not->toBeNull()
        ->and($confirmed->sale->status)->toBe(SaleStatus::Confirmed)
        ->and($confirmed->sale->invoice_no)->toStartWith('INV-')
        ->and($confirmed->sale->total)->toBe(6000)
        ->and($confirmed->sale->balance_due)->toBe(6000)
        ->and($taken->fresh()->stock_on_hand)->toBe(4)
        ->and($taken->fresh()->cost_price)->toBe(1100)
        ->and($gave->fresh()->stock_on_hand)->toBe(8);

    expect(StockMovement::query()->where('product_id', $taken->id)->where('type', StockMovementType::ReceiptIn)->count())->toBe(1);
    expect(StockMovement::query()->where('product_id', $gave->id)->where('type', StockMovementType::SaleOut)->count())->toBe(1);
});

test('confirm only-in receives stock without a sale', function () {
    $product = Product::factory()->create(['cost_price' => 800, 'selling_price' => 1600]);
    $product->stock_on_hand = 1;
    $product->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Only In',
        'settlement' => 'pay_later',
        'items' => [
            ['direction' => 'in', 'product_id' => $product->id, 'quantity' => 3],
        ],
    ]);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange);

    expect($confirmed->sale_id)->toBeNull()
        ->and($confirmed->goods_receipt_id)->not->toBeNull()
        ->and($product->fresh()->stock_on_hand)->toBe(4)
        ->and($product->fresh()->cost_price)->toBe(800);
});

test('confirm only-out creates sale without goods receipt', function () {
    $product = Product::factory()->create(['selling_price' => 2500, 'cost_price' => 1000]);
    $product->stock_on_hand = 5;
    $product->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Only Out',
        'settlement' => 'cash',
        'items' => [
            ['direction' => 'out', 'product_id' => $product->id, 'quantity' => 2],
        ],
    ]);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange);

    expect($confirmed->goods_receipt_id)->toBeNull()
        ->and($confirmed->sale_id)->not->toBeNull()
        ->and($confirmed->sale->total)->toBe(5000)
        ->and($product->fresh()->stock_on_hand)->toBe(3);
});

test('confirm warns on credit limit then succeeds with override', function () {
    $this->customer->credit_limit = 1000;
    $this->customer->outstanding_balance = 0;
    $this->customer->save();

    $product = Product::factory()->create(['selling_price' => 2000]);
    $product->stock_on_hand = 5;
    $product->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Credit Dealer',
        'settlement' => 'pay_later',
        'items' => [
            ['direction' => 'out', 'product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect(fn () => app(ConfirmStockExchange::class)->execute($this->owner, $exchange))
        ->toThrow(CreditLimitExceededException::class);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange->fresh(), [
        'override_credit_limit' => true,
    ]);

    expect($confirmed->status)->toBe(StockExchangeStatus::Confirmed)
        ->and($confirmed->sale->total)->toBe(2000);
});

test('update draft and cancel draft', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $a->stock_on_hand = 0;
    $a->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Temp',
        'settlement' => 'cash',
        'items' => [
            ['direction' => 'in', 'product_id' => $a->id, 'quantity' => 1],
        ],
    ]);

    $updated = app(UpdateDraftStockExchange::class)->execute($this->owner, $exchange, [
        'counterparty_name' => 'Renamed',
        'settlement' => 'installment',
        'items' => [
            ['direction' => 'out', 'product_id' => $b->id, 'quantity' => 2],
            ['direction' => 'in', 'product_id' => $a->id, 'quantity' => 5],
        ],
    ]);

    expect($updated->counterparty_name)->toBe('Renamed')
        ->and($updated->items)->toHaveCount(2)
        ->and($updated->items->firstWhere('direction', StockExchangeDirection::In)->quantity)->toBe(5);

    app(CancelStockExchange::class)->execute($this->owner, $updated);

    expect(StockExchange::query()->find($updated->id))->toBeNull();
});

test('void confirmed exchange reverses sale and receipt stock', function () {
    $taken = Product::factory()->create(['cost_price' => 500, 'selling_price' => 1000]);
    $taken->stock_on_hand = 0;
    $taken->save();

    $gave = Product::factory()->create(['cost_price' => 700, 'selling_price' => 1400]);
    $gave->stock_on_hand = 8;
    $gave->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Void Me',
        'settlement' => 'pay_later',
        'items' => [
            ['direction' => 'in', 'product_id' => $taken->id, 'quantity' => 3],
            ['direction' => 'out', 'product_id' => $gave->id, 'quantity' => 2],
        ],
    ]);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange);
    expect($taken->fresh()->stock_on_hand)->toBe(3)
        ->and($gave->fresh()->stock_on_hand)->toBe(6);

    $voided = app(VoidStockExchange::class)->execute($this->owner, $confirmed, 'Wrong dealer');

    expect($voided->status)->toBe(StockExchangeStatus::Void)
        ->and($voided->sale->status)->toBe(SaleStatus::Void)
        ->and($taken->fresh()->stock_on_hand)->toBe(0)
        ->and($gave->fresh()->stock_on_hand)->toBe(8);
});

test('void refuses when sale has payments', function () {
    $product = Product::factory()->create(['selling_price' => 1000]);
    $product->stock_on_hand = 5;
    $product->save();

    $exchange = app(CreateStockExchange::class)->execute($this->owner, [
        'customer_id' => $this->customer->id,
        'counterparty_name' => 'Paid',
        'settlement' => 'cash',
        'items' => [
            ['direction' => 'out', 'product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $confirmed = app(ConfirmStockExchange::class)->execute($this->owner, $exchange);
    $confirmed->sale->amount_paid = 500;
    $confirmed->sale->balance_due = 500;
    $confirmed->sale->save();

    expect(fn () => app(VoidStockExchange::class)->execute($this->owner, $confirmed->fresh(), 'nope'))
        ->toThrow(StockExchangeStateException::class);
});
