<?php

uses()->group('mysql');

use App\Actions\Sales\ConfirmSale;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\MoneyInvariants;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertPaymentRow(int $customerId, int $userId, int $amount, int $unallocated = 0): int
{
    return DB::table('payments')->insertGetId([
        'receipt_no' => 'RCT-T-'.uniqid(),
        'customer_id' => $customerId,
        'amount' => $amount,
        'method' => 'cash',
        'paid_at' => now(),
        'unallocated_amount' => $unallocated,
        'status' => 'valid',
        'received_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('payments enforce amount > 0 and unallocated <= amount at the database', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();

    expect(fn () => insertPaymentRow($customer->id, $user->id, 0))->toThrow(QueryException::class)
        ->and(fn () => insertPaymentRow($customer->id, $user->id, 1000, 1001))->toThrow(QueryException::class)
        ->and(insertPaymentRow($customer->id, $user->id, 1000, 1000))->toBeInt();
});

test('allocation amounts are signed and reversal_of_id is unique', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $sale = app(ConfirmSale::class)->execute($user, makeDraftSale($user, [[stockedProduct(stock: 5, price: 1000), 1]], $customer));
    $paymentId = insertPaymentRow($customer->id, $user->id, 1000);

    $original = PaymentAllocation::query()->create(['payment_id' => $paymentId, 'sale_id' => $sale->id, 'amount' => 1000, 'created_by' => $user->id]);
    $reversal = PaymentAllocation::query()->create(['payment_id' => $paymentId, 'sale_id' => $sale->id, 'amount' => -1000, 'reversal_of_id' => $original->id, 'created_by' => $user->id]);

    expect($reversal->fresh()->amount)->toBe(-1000)
        ->and(fn () => PaymentAllocation::query()->create(['payment_id' => $paymentId, 'sale_id' => $sale->id, 'amount' => -1000, 'reversal_of_id' => $original->id, 'created_by' => $user->id]))
        ->toThrow(QueryException::class);
});

test('backfill and invariant queries run on MySQL', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    app(ConfirmSale::class)->execute($user, makeDraftSale($user, [[stockedProduct(stock: 5, price: 1500), 2]], $customer));
    DB::table('customers')->update(['outstanding_balance' => 0]);

    (require database_path('migrations/2026_10_02_100003_backfill_customers_outstanding_balance.php'))->up();

    expect($customer->fresh()->outstanding_balance)->toBe(3000)
        ->and(app(MoneyInvariants::class)->check())->toBe([]);

    // Overpaid sale: amount_paid > total must be reported, not crash on unsigned maths.
    DB::table('sales')->update(['amount_paid' => 999999]);
    expect(collect(app(MoneyInvariants::class)->check())->pluck('invariant')->all())
        ->toContain('sale_amount_paid', 'sale_balance_due');
});
