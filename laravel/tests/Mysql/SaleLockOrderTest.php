<?php

uses()->group('mysql');

use App\Actions\Sales\ConfirmSale;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;

function lockOrderConnection(): PDO
{
    $config = config('database.connections.mysql_testing');

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * @return array{0: resource, 1: array<int, resource>}
 */
function startVoidWorker(int $saleId, int $userId, float $startAt = 0): array
{
    $proc = proc_open(
        [PHP_BINARY, base_path('tests/Mysql/bin/void_sale_worker.php'), (string) $saleId, (string) $userId, sprintf('%.6f', $startAt)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );
    expect($proc)->toBeResource();
    fclose($pipes[0]);

    return [$proc, $pipes];
}

/**
 * @param  array{0: resource, 1: array<int, resource>}  $worker
 * @return array{exit: int, out: string, err: string}
 */
function finishWorker(array $worker): array
{
    [$proc, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($proc), 'out' => trim($out), 'err' => trim($err)];
}

/**
 * Waits until another connection is running (i.e. blocked on) a locking read of the
 * customers table. PROCESSLIST shows the same user's own threads without the PROCESS
 * privilege; the observer itself is idle, so its INFO is NULL.
 */
function waitForCustomerLockWait(PDO $observer, int $timeoutSeconds = 20): bool
{
    $sql = "SELECT COUNT(*) FROM information_schema.PROCESSLIST
            WHERE ID <> CONNECTION_ID() AND INFO LIKE '%from `customers`%for update%'";

    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $waiting = (int) $observer->query($sql)->fetchColumn();
        if ($waiting > 0) {
            return true;
        }
        usleep(100_000);
    }

    return false;
}

test('void takes the customer lock before the sale lock', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = app(ConfirmSale::class)->execute($user, makeDraftSale($user, [[$product, 3]], $customer));

    // Connection A plays a payment holding the customer.
    $a = lockOrderConnection();
    $a->beginTransaction();
    $a->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customer->id]);

    $worker = startVoidWorker($sale->id, $user->id);

    try {
        expect(waitForCustomerLockWait(lockOrderConnection()))->toBeTrue('void worker never blocked on the customer lock');

        // While void waits on the customer, it must not hold the sale: B can lock it.
        $b = lockOrderConnection();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $b->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE')->execute([$sale->id]);
        $b->rollBack();

        expect(Sale::query()->find($sale->id)->status)->toBe(SaleStatus::Confirmed);
    } finally {
        $a->rollBack();
    }

    $result = finishWorker($worker);

    expect($result['exit'])->toBe(0, json_encode($result))
        ->and($sale->fresh()->status)->toBe(SaleStatus::Void)
        ->and($product->fresh()->stock_on_hand)->toBe(10);
});

test('the same sale voided twice at once is voided exactly once', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = app(ConfirmSale::class)->execute($user, makeDraftSale($user, [[$product, 4]], $customer));

    expect($product->fresh()->stock_on_hand)->toBe(6);

    // Start both before collecting either; both wake at the same instant.
    $startAt = microtime(true) + 2.0;
    $workers = [
        startVoidWorker($sale->id, $user->id, $startAt),
        startVoidWorker($sale->id, $user->id, $startAt),
    ];
    $results = array_map(fn (array $worker): array => finishWorker($worker), $workers);

    $exits = array_column($results, 'exit');
    sort($exits);

    expect($exits)->toBe([0, 4], json_encode($results))
        ->and($sale->fresh()->status)->toBe(SaleStatus::Void)
        ->and($product->fresh()->stock_on_hand)->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::SaleVoidIn)->count())->toBe(1);
});
