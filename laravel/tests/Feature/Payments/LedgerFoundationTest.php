<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\VoidSale;
use App\Console\Commands\ReconcileCustomersCommand;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CreditLimitExceededException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\MoneyInvariants;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Carbon::setTestNow());

function invariantNames(): array
{
    return collect(app(MoneyInvariants::class)->check())->pluck('invariant')->unique()->sort()->values()->all();
}

/**
 * Two customers with confirmed sales, one partly paid, one voided, one draft.
 *
 * @return array{0: Customer, 1: Customer, 2: Sale, 3: Sale}
 */
function seedReceivables(User $owner): array
{
    $product = stockedProduct(stock: 500, price: 1000);
    $school = Customer::factory()->create(['credit_limit' => null]);
    $reseller = Customer::factory()->create(['credit_limit' => null]);

    $paid = app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[$product, 10]], $school));
    $open = app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[$product, 4]], $school));
    $voided = app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[$product, 3]], $reseller));
    app(VoidSale::class)->execute($owner, $voided, 'Wrong customer');
    app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[$product, 2]], $reseller));
    makeDraftSale($owner, [[$product, 1]], $reseller);

    recordPayment($owner, $school, 6000, ['allocations' => [['sale_id' => $paid->id, 'amount' => 6000]]]);

    return [$school->fresh(), $reseller->fresh(), $paid->fresh(), $open->fresh()];
}

// --- Cached outstanding balance -------------------------------------------------------------

test('confirm and void maintain customers.outstanding_balance', function () {
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $product = stockedProduct(stock: 100, price: 1000);

    $a = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 5]], $customer));
    $b = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 2]], $customer));
    expect($customer->fresh()->outstanding_balance)->toBe(7000);

    app(VoidSale::class)->execute($this->owner, $a, 'Duplicate');
    expect($customer->fresh()->outstanding_balance)->toBe(2000)
        ->and($b->fresh()->balance_due)->toBe(2000);

    assertMoneyInvariants();
});

test('the credit check reads the locked customer cache, not a sum over sales', function () {
    $customer = Customer::factory()->create(['credit_limit' => 10000]);
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1000), 2]], $customer);

    // Only the cache says this customer owes 9,000; no sales back it.
    DB::table('customers')->where('id', $customer->id)->update(['outstanding_balance' => 9000]);

    try {
        app(ConfirmSale::class)->execute($this->owner, $sale);
        $this->fail('Expected CreditLimitExceededException');
    } catch (CreditLimitExceededException $e) {
        expect($e->details()['outstanding'])->toBe(9000)
            ->and($e->details()['projected_balance'])->toBe(11000);
    }
});

test('a confirmed zero-total sale is derived as paid', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1, 0, 'Free sample']]));

    expect($sale->total)->toBe(0)
        ->and($sale->payment_status)->toBe(PaymentStatus::Paid);

    assertMoneyInvariants();
});

// --- Invariants --------------------------------------------------------------------------------

test('invariants hold across confirm, partial payment, void and draft scenarios', function () {
    [$school, $reseller, $paid, $open] = seedReceivables($this->owner);

    expect($paid->amount_paid)->toBe(6000)
        ->and($paid->balance_due)->toBe(4000)
        ->and($paid->payment_status)->toBe(PaymentStatus::Partial)
        ->and($school->outstanding_balance)->toBe(4000 + 4000)
        ->and($reseller->outstanding_balance)->toBe(2000)
        ->and(app(MoneyInvariants::class)->check())->toBe([]);
});

test('every cache invariant detects tampering', function (Closure $tamper, string $invariant) {
    [$school, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();

    $tamper($school, $paid, $payment);

    expect(invariantNames())->toContain($invariant);
})->with([
    'sale amount_paid' => [fn ($c, $s) => DB::table('sales')->where('id', $s->id)->update(['amount_paid' => 1000]), 'sale_amount_paid'],
    'sale balance_due' => [fn ($c, $s) => DB::table('sales')->where('id', $s->id)->update(['balance_due' => 1]), 'sale_balance_due'],
    'sale payment_status' => [fn ($c, $s) => DB::table('sales')->where('id', $s->id)->update(['payment_status' => 'paid']), 'sale_payment_status'],
    'payment unallocated' => [fn ($c, $s, $p) => DB::table('payments')->where('id', $p->id)->update(['unallocated_amount' => 5]), 'payment_allocations'],
    'customer credit' => [fn ($c) => DB::table('customers')->where('id', $c->id)->update(['credit_balance' => 700]), 'customer_credit_balance'],
    'customer outstanding' => [fn ($c) => DB::table('customers')->where('id', $c->id)->update(['outstanding_balance' => 1]), 'customer_outstanding'],
    'void payment still allocated' => [fn ($c, $s, $p) => DB::table('payments')->where('id', $p->id)->update(['status' => 'void']), 'payment_allocations'],
    'balance on a void sale' => [fn ($c, $s) => DB::table('sales')->where('status', 'void')->update(['balance_due' => 3000]), 'sale_balance_due'],
]);

test('ledger rules: originals positive, reversals exactly negate one original', function () {
    [, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();
    $original = PaymentAllocation::query()->firstOrFail();
    $now = now();

    // A correct reversal pair is fine (and nets the sale to zero, so fix the caches too).
    DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id, 'sale_id' => $paid->id, 'amount' => -6000,
        'reversal_of_id' => $original->id, 'created_by' => $this->owner->id, 'created_at' => $now,
    ]);
    expect(invariantNames())->not->toContain('allocation_ledger');

    // A non-positive original and a reversal of a reversal are both reported.
    $reversalId = (int) DB::table('payment_allocations')->max('id');
    DB::table('payment_allocations')->insert([
        ['payment_id' => $payment->id, 'sale_id' => $paid->id, 'amount' => -5, 'reversal_of_id' => null, 'created_by' => $this->owner->id, 'created_at' => $now],
        ['payment_id' => $payment->id, 'sale_id' => $paid->id, 'amount' => 6000, 'reversal_of_id' => $reversalId, 'created_by' => $this->owner->id, 'created_at' => $now],
    ]);

    $ledger = collect(app(MoneyInvariants::class)->check())->where('invariant', 'allocation_ledger');
    expect($ledger)->toHaveCount(2);
});

test('an allocation can be reversed only once', function () {
    [, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();
    $original = PaymentAllocation::query()->firstOrFail();

    $reverse = fn () => PaymentAllocation::query()->create([
        'payment_id' => $payment->id, 'sale_id' => $paid->id, 'amount' => -6000,
        'reversal_of_id' => $original->id, 'created_by' => $this->owner->id,
    ]);

    $reverse();
    expect($reverse)->toThrow(QueryException::class);
});

// --- Reconcile command ----------------------------------------------------------------------------

test('reconcile passes on consistent data', function () {
    seedReceivables($this->owner);

    $this->artisan('customers:reconcile')
        ->expectsOutputToContain('All money invariants hold.')
        ->assertSuccessful();
});

test('reconcile reports tampered caches without changing them', function () {
    [$school, , $paid] = seedReceivables($this->owner);
    DB::table('customers')->where('id', $school->id)->update(['outstanding_balance' => 1, 'credit_balance' => 99]);
    DB::table('sales')->where('id', $paid->id)->update(['amount_paid' => 0]);

    $this->artisan('customers:reconcile')
        ->expectsOutputToContain('customer_outstanding')
        ->expectsOutputToContain('customer_credit_balance')
        ->expectsOutputToContain('sale_amount_paid')
        ->expectsOutputToContain('Run with --fix')
        ->assertFailed();

    expect($school->fresh()->outstanding_balance)->toBe(1)
        ->and($paid->fresh()->amount_paid)->toBe(0);
});

test('reconcile --fix rebuilds every cache from the ledger', function () {
    [$school, $reseller, $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();

    DB::table('customers')->where('id', $school->id)->update(['outstanding_balance' => 1, 'credit_balance' => 99]);
    DB::table('customers')->where('id', $reseller->id)->update(['outstanding_balance' => 0]);
    DB::table('sales')->where('id', $paid->id)->update(['amount_paid' => 0, 'balance_due' => 10000, 'payment_status' => 'unpaid']);
    DB::table('payments')->where('id', $payment->id)->update(['unallocated_amount' => 6000]);

    $this->artisan('customers:reconcile --fix')
        ->expectsOutputToContain('Repaired')
        ->expectsOutputToContain('All money invariants hold.')
        ->assertSuccessful();

    expect($paid->fresh()->amount_paid)->toBe(6000)
        ->and($paid->fresh()->balance_due)->toBe(4000)
        ->and($paid->fresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and($payment->fresh()->unallocated_amount)->toBe(0)
        ->and($school->fresh()->credit_balance)->toBe(0)
        ->and($school->fresh()->outstanding_balance)->toBe(8000)
        ->and($reseller->fresh()->outstanding_balance)->toBe(2000);

    assertMoneyInvariants();
});

test('reconcile --fix never touches ledger corruption and still fails', function () {
    [, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();
    DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id, 'sale_id' => $paid->id, 'amount' => -1,
        'reversal_of_id' => null, 'created_by' => $this->owner->id, 'created_at' => now(),
    ]);
    $rows = DB::table('payment_allocations')->count();

    $this->artisan('customers:reconcile --fix')
        ->expectsOutputToContain('need manual investigation')
        ->expectsOutputToContain('allocation_ledger')
        ->assertFailed();

    expect(DB::table('payment_allocations')->count())->toBe($rows);
});

test('reconcile --customer limits the check', function () {
    [$school, $reseller] = seedReceivables($this->owner);
    DB::table('customers')->where('id', $school->id)->update(['outstanding_balance' => 1]);

    $this->artisan('customers:reconcile', ['--customer' => [$reseller->id]])->assertSuccessful();
    $this->artisan('customers:reconcile', ['--customer' => [$school->id]])->assertFailed();
});

// --- Backfill migration -------------------------------------------------------------------------

test('the outstanding balance backfill sets each customer from confirmed sales and is re-runnable', function () {
    [$school, $reseller] = seedReceivables($this->owner);
    $untouched = Customer::factory()->create();
    DB::table('customers')->update(['outstanding_balance' => 0]);

    $backfill = require database_path('migrations/2026_10_02_100003_backfill_customers_outstanding_balance.php');
    $backfill->up();
    $backfill->up();

    expect($school->fresh()->outstanding_balance)->toBe(8000)
        ->and($reseller->fresh()->outstanding_balance)->toBe(2000)
        ->and($untouched->fresh()->outstanding_balance)->toBe(0);

    assertMoneyInvariants();
});

// --- Models, policy, morph map, audit ------------------------------------------------------------

test('payments and allocations are immutable financial records', function () {
    [, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();
    $allocation = PaymentAllocation::query()->firstOrFail();

    expect(fn () => $payment->delete())->toThrow(LogicException::class)
        ->and(fn () => $allocation->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $allocation->delete())->toThrow(LogicException::class)
        ->and($this->owner->can('delete', $payment))->toBeFalse()
        ->and($paid->allocations()->count())->toBe(1);
});

test('cached money fields are not mass assignable', function () {
    $payment = new Payment(['amount' => 1000, 'unallocated_amount' => 1000]);
    $customer = new Customer(['name' => 'X', 'credit_balance' => 5, 'outstanding_balance' => 5]);

    expect($payment->amount)->toBe(1000)
        ->and($payment->unallocated_amount)->toBeNull()
        ->and($customer->credit_balance)->toBeNull()
        ->and($customer->outstanding_balance)->toBeNull();
});

test('payment and allocation morph aliases are short names', function () {
    expect((new Payment)->getMorphClass())->toBe('payment')
        ->and((new PaymentAllocation)->getMorphClass())->toBe('payment_allocation');
});

test('payment changes are activity-logged with the acting user', function () {
    $this->actingAs($this->owner);
    [, , $paid] = seedReceivables($this->owner);
    $payment = Payment::query()->firstOrFail();

    $payment->update(['status' => PaymentRecordStatus::Void, 'void_reason' => 'Bounced cheque']);

    $log = Activity::query()->where('subject_type', 'payment')->where('subject_id', $payment->id)
        ->where('event', 'updated')->latest('id')->first();

    expect($log->causer_id)->toBe($this->owner->id)
        ->and($log->attribute_changes['attributes']['status'])->toBe('void')
        ->and($log->attribute_changes['attributes']['void_reason'])->toBe('Bounced cheque');
});

test('payment enums', function () {
    expect(PaymentMethod::Cash->requiresReference())->toBeFalse()
        ->and(PaymentMethod::Momo->requiresReference())->toBeTrue()
        ->and(PaymentMethod::BankTransfer->requiresReference())->toBeTrue()
        ->and(PaymentMethod::Cheque->requiresReference())->toBeTrue()
        ->and(PaymentStatus::derive(1000, 0))->toBe(PaymentStatus::Unpaid)
        ->and(PaymentStatus::derive(1000, 1))->toBe(PaymentStatus::Partial)
        ->and(PaymentStatus::derive(1000, 1000))->toBe(PaymentStatus::Paid)
        ->and(PaymentStatus::derive(0, 0))->toBe(PaymentStatus::Paid);
});

test('a failed scheduled reconcile is logged as critical with the violation count', function () {
    [$school] = seedReceivables($this->owner);
    DB::table('customers')->where('id', $school->id)->update(['outstanding_balance' => 1, 'credit_balance' => 2]);

    Log::spy();

    ReconcileCustomersCommand::logScheduledFailure();

    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'customers:reconcile')
        && $context['violations'] === 2
        && $context['invariants'] === ['customer_credit_balance' => 1, 'customer_outstanding' => 1]
        && $context['customers'] === [$school->id]);
});

test('the reconcile command is scheduled with a failure hook', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'customers:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *');

    // onFailure registers an after-callback; run it against clean data to prove it is wired.
    Log::spy();
    $event->exitCode = 1;
    foreach ((new ReflectionProperty($event, 'afterCallbacks'))->getValue($event) as $callback) {
        app()->call($callback);
    }
    Log::shouldHaveReceived('critical')->once();
});
