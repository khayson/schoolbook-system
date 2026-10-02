<?php

use App\Actions\Sales\CreateDraftSale;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\MoneyInvariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * Fixture until RecordPayment exists (2C.2): a valid cash payment fully allocated to one
 * confirmed sale, with every cache kept consistent with the ledger.
 */
function applyLedgerPayment(Sale $sale, int $amount, User $user): Payment
{
    return DB::transaction(function () use ($sale, $amount, $user): Payment {
        $customer = Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
        $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

        $payment = Payment::query()->create([
            'receipt_no' => 'RCT-TEST-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount' => $amount,
            'method' => PaymentMethod::Cash,
            'paid_at' => now(),
            'status' => PaymentRecordStatus::Valid,
            'received_by' => $user->id,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'amount' => $amount,
            'created_by' => $user->id,
        ]);

        $sale->amount_paid += $amount;
        $sale->balance_due -= $amount;
        $sale->payment_status = PaymentStatus::derive($sale->total, $sale->amount_paid);
        $sale->save();

        $customer->outstanding_balance -= $amount;
        $customer->save();

        return $payment;
    });
}
