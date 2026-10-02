<?php

uses()->group('mysql');

use App\Actions\Payments\VoidPayment;
use App\Actions\Sales\ConfirmSale;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Services\MoneyInvariants;

/**
 * Starts every worker, then collects them; all wake at the same instant.
 *
 * @param  list<list<string>>  $argSets  arguments after the script name; '{start}' is replaced
 * @return list<array{exit: int, out: string, err: string}>
 */
function runLedgerWorkers(array $argSets): array
{
    $script = base_path('tests/Mysql/bin/ledger_worker.php');
    $startAt = sprintf('%.6f', microtime(true) + 2.0);

    $running = [];
    foreach ($argSets as $args) {
        $args = array_map(fn (string $a): string => $a === '{start}' ? $startAt : $a, $args);
        $proc = proc_open(
            [PHP_BINARY, $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        expect($proc)->toBeResource();
        fclose($pipes[0]);
        $running[] = [$proc, $pipes];
    }

    $results = [];
    foreach ($running as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $results[] = ['exit' => proc_close($proc), 'out' => trim($out), 'err' => trim($err)];
    }

    return $results;
}

function concurrencyInvoice(User $owner, Customer $customer, int $total): Sale
{
    return app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[stockedProduct(stock: 1000, price: $total), 1]], $customer));
}

function expectNoInvariantViolations(): void
{
    $violations = app(MoneyInvariants::class)->check();
    expect($violations)->toBe([], implode("\n", array_map('strval', $violations)));
}

test('two concurrent auto payments never double-allocate one invoice', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $invoice = concurrencyInvoice($owner, $customer, 1000);

    $results = runLedgerWorkers([
        ['record_payment', (string) $customer->id, (string) $owner->id, '1000', '{start}'],
        ['record_payment', (string) $customer->id, (string) $owner->id, '1000', '{start}'],
    ]);

    expect(array_column($results, 'exit'))->toBe([0, 0], json_encode($results))
        ->and((int) PaymentAllocation::query()->where('sale_id', $invoice->id)->sum('amount'))->toBe(1000)
        ->and($invoice->fresh()->balance_due)->toBe(0)
        ->and(Payment::query()->orderBy('unallocated_amount')->pluck('unallocated_amount')->all())->toBe([0, 1000])
        ->and($customer->fresh()->credit_balance)->toBe(1000);

    expectNoInvariantViolations();
});

test('two concurrent explicit payments to the same invoice: exactly one is applied', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $invoice = concurrencyInvoice($owner, $customer, 1000);

    $results = runLedgerWorkers([
        ['record_payment', (string) $customer->id, (string) $owner->id, '1000', '{start}', (string) $invoice->id],
        ['record_payment', (string) $customer->id, (string) $owner->id, '1000', '{start}', (string) $invoice->id],
    ]);

    $outcomes = array_column($results, 'out');
    sort($outcomes);

    expect(array_column($results, 'exit'))->toContain(0, 8)
        ->and($outcomes[1])->toBe('sale_not_payable', json_encode($results))
        ->and(Payment::query()->count())->toBe(1)
        ->and($invoice->fresh()->amount_paid)->toBe(1000);

    expectNoInvariantViolations();
});

test('a payment and a void of the same customer\'s invoice never deadlock', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);

    for ($round = 1; $round <= 4; $round++) {
        $invoice = concurrencyInvoice($owner, $customer, 1000);
        recordPayment($owner, $customer, 300, ['allocations' => [['sale_id' => $invoice->id, 'amount' => 300]]]);

        $results = runLedgerWorkers([
            ['void_sale', (string) $invoice->id, (string) $owner->id, '{start}'],
            ['record_payment', (string) $customer->id, (string) $owner->id, '500', '{start}'],
        ]);

        expect(array_column($results, 'exit'))->toBe([0, 0], "round {$round}: ".json_encode($results))
            ->and($invoice->fresh()->status)->toBe(SaleStatus::Void)
            ->and($invoice->fresh()->amount_paid)->toBe(0);

        expectNoInvariantViolations();
    }

    // All money ends up as credit: 4 x (300 + 500), whichever order each round ran in.
    expect($customer->fresh()->credit_balance)->toBe(3200)
        ->and($customer->fresh()->outstanding_balance)->toBe(0);
});

test('a payment racing a confirm: the credit check sees one serial order or the other', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    concurrencyInvoice($owner, $customer, 800);
    $draft = makeDraftSale($owner, [[stockedProduct(stock: 10, price: 500), 1]], $customer);

    $results = runLedgerWorkers([
        ['confirm', (string) $draft->id, (string) $owner->id, '{start}'],
        ['record_payment', (string) $customer->id, (string) $owner->id, '400', '{start}'],
    ]);

    [$confirm, $payment] = $results;
    expect($payment['exit'])->toBe(0, json_encode($results))
        ->and($confirm['exit'])->toBeIn([0, 6], json_encode($results));

    $customer->refresh();
    if ($confirm['exit'] === 0) {
        // Payment first (800 -> 400 outstanding), then confirm: 400 + 500 <= 1000.
        expect($draft->fresh()->status)->toBe(SaleStatus::Confirmed)
            ->and($customer->outstanding_balance)->toBe(900);
    } else {
        // Confirm first: 800 + 500 > 1000 -> warning; then the payment.
        expect($draft->fresh()->status)->toBe(SaleStatus::Draft)
            ->and($customer->outstanding_balance)->toBe(400);
    }

    expectNoInvariantViolations();
});

test('concurrent payments get unique, gapless receipt numbers', function () {
    $owner = User::factory()->owner()->create();
    $customers = Customer::factory()->count(6)->create(['credit_limit' => null]);

    $results = runLedgerWorkers($customers->map(
        fn (Customer $c): array => ['record_payment', (string) $c->id, (string) $owner->id, '250', '{start}']
    )->all());

    $year = (int) now()->format('Y');

    expect(array_column($results, 'exit'))->toBe(array_fill(0, 6, 0), json_encode($results))
        ->and(Payment::query()->pluck('receipt_no')->sort()->values()->all())
        ->toBe(array_map(fn (int $n): string => sprintf('RCT-%d-%06d', $year, $n), range(1, 6)));

    expectNoInvariantViolations();
});

test('two simultaneous payments with the same MoMo reference: exactly one is recorded', function () {
    $owner = User::factory()->owner()->create();
    // Different customers, so the customer lock does not serialize them: they meet only at the unique index.
    $a = Customer::factory()->create(['credit_limit' => null]);
    $b = Customer::factory()->create(['credit_limit' => null]);

    $results = runLedgerWorkers([
        ['record_payment', (string) $a->id, (string) $owner->id, '5000', '{start}', '0', 'momo', 'MP261002.RACE'],
        ['record_payment', (string) $b->id, (string) $owner->id, '5000', '{start}', '0', 'momo', 'mp261002.race'],
    ]);

    $exits = array_column($results, 'exit');
    sort($exits);
    $outs = array_column($results, 'out');

    expect($exits)->toBe([0, 8], json_encode($results))
        ->and($outs)->toContain('duplicate_reference')
        ->and(Payment::query()->where('method', 'momo')->count())->toBe(1)
        ->and($a->fresh()->credit_balance + $b->fresh()->credit_balance)->toBe(5000);

    expectNoInvariantViolations();
});

test('generated reference_key works on MySQL and a void frees it', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);

    $first = recordPayment($owner, $customer, 100, ['method' => 'cheque', 'reference' => '000 77']);
    expect($first->fresh()->reference_key)->toBe('cheque:00077');

    app(VoidPayment::class)->execute($owner, $first, 'Bounced');
    expect($first->fresh()->reference_key)->toBeNull();

    $again = recordPayment($owner, $customer, 100, ['method' => 'cheque', 'reference' => '00077']);
    expect($again->fresh()->reference_key)->toBe('cheque:00077');

    expectNoInvariantViolations();
});

test('the unique index (not the pre-check) catches a reference committed while the request was in flight', function () {
    $owner = User::factory()->owner()->create();
    $a = Customer::factory()->create(['credit_limit' => null]);
    $b = Customer::factory()->create(['credit_limit' => null]);

    // Connection A records the reference but does not commit yet: the worker's plain
    // pre-check cannot see it, so the worker's INSERT waits on the unique index.
    $config = config('database.connections.mysql_testing');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->beginTransaction();
    $pdo->prepare("insert into payments (receipt_no, customer_id, amount, method, reference, paid_at, unallocated_amount, status, received_by, created_at, updated_at)
        values ('RCT-HELD-1', ?, 700, 'momo', 'MP-INFLIGHT', now(), 0, 'valid', ?, now(), now())")
        ->execute([$a->id, $owner->id]);

    $script = base_path('tests/Mysql/bin/ledger_worker.php');
    $proc = proc_open(
        [PHP_BINARY, $script, 'record_payment', (string) $b->id, (string) $owner->id, '700', '0', '0', 'momo', 'MP-INFLIGHT'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );
    fclose($pipes[0]);

    // Wait until the worker is blocked on its INSERT, then commit A.
    $observer = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
    );
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $waiting = (int) $observer->query("select count(*) from information_schema.PROCESSLIST where ID <> connection_id() and INFO like 'insert into `payments`%'")->fetchColumn();
        if ($waiting > 0) {
            break;
        }
        usleep(100_000);
    }
    expect($waiting)->toBe(1, 'worker never blocked on the unique index');
    $pdo->commit();

    $out = trim(stream_get_contents($pipes[1]));
    $err = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(8, $out.' '.$err)
        ->and($out)->toBe('duplicate_reference')
        ->and(Payment::query()->where('reference', 'MP-INFLIGHT')->pluck('receipt_no')->all())->toBe(['RCT-HELD-1'])
        ->and($b->fresh()->credit_balance)->toBe(0);
});
