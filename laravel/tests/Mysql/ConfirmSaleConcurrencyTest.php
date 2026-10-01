<?php

uses()->group('mysql');

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Runs one confirm_sale_worker per sale id at the same instant.
 *
 * @param  list<int>  $saleIds
 * @return list<array{exit: int, out: string, err: string}>
 */
function runConfirmWorkers(array $saleIds, int $userId): array
{
    $worker = base_path('tests/Mysql/bin/confirm_sale_worker.php');
    $startAt = sprintf('%.6f', microtime(true) + 2.0); // after every worker has booted

    $running = [];
    foreach ($saleIds as $saleId) {
        $proc = proc_open(
            [PHP_BINARY, $worker, (string) $saleId, (string) $userId, $startAt],
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

test('concurrent confirmations produce unique, gapless invoice numbers and correct stock', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $shared = stockedProduct(stock: 100, price: 1000);

    $workers = 6;
    $saleIds = [];
    $expectedShared = 100;
    for ($i = 1; $i <= $workers; $i++) {
        $own = stockedProduct(stock: 10, price: 500);
        $saleIds[] = makeDraftSale($user, [[$own, 1], [$shared, $i]], $customer)->id;
        $expectedShared -= $i;
    }

    $results = runConfirmWorkers($saleIds, $user->id);

    expect(array_column($results, 'exit'))->toBe(array_fill(0, $workers, 0), json_encode($results));

    $year = (int) now()->format('Y');
    $expectedNumbers = array_map(fn (int $n): string => sprintf('INV-%d-%06d', $year, $n), range(1, $workers));

    $numbers = Sale::query()->whereIn('id', $saleIds)->pluck('invoice_no')->sort()->values()->all();
    expect($numbers)->toBe($expectedNumbers)
        ->and((int) NumberSequence::query()->where('key', 'inv')->where('year', $year)->value('last_number'))->toBe($workers)
        ->and(Sale::query()->whereIn('id', $saleIds)->where('status', SaleStatus::Confirmed)->count())->toBe($workers);

    // The shared product's ledger is one consistent chain in id order.
    expect($shared->fresh()->stock_on_hand)->toBe($expectedShared);

    $balance = 100;
    foreach (StockMovement::query()->where('product_id', $shared->id)->orderBy('id')->get() as $movement) {
        $balance += $movement->quantity;
        expect($movement->balance_after)->toBe($balance);
    }
    expect($balance)->toBe($expectedShared);
});

test('two confirmations competing for the last unit: exactly one wins', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $lastUnit = stockedProduct(stock: 1, price: 1000);

    $first = makeDraftSale($user, [[$lastUnit, 1]], $customer);
    $second = makeDraftSale($user, [[$lastUnit, 1]], $customer);

    $results = runConfirmWorkers([$first->id, $second->id], $user->id);

    $exits = array_column($results, 'exit');
    sort($exits);
    expect($exits)->toBe([0, 3], json_encode($results));

    $year = (int) now()->format('Y');
    $statuses = Sale::query()->whereIn('id', [$first->id, $second->id])->pluck('status')->map->value->sort()->values()->all();

    expect($statuses)->toBe(['confirmed', 'draft'])
        ->and($lastUnit->fresh()->stock_on_hand)->toBe(0)
        ->and(StockMovement::query()->where('product_id', $lastUnit->id)->count())->toBe(1)
        ->and(Sale::query()->whereNotNull('invoice_no')->pluck('invoice_no')->all())->toBe([sprintf('INV-%d-000001', $year)])
        ->and((int) NumberSequence::query()->where('key', 'inv')->where('year', $year)->value('last_number'))->toBe(1);
});

test('the same sale confirmed twice at once is confirmed exactly once', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = makeDraftSale($user, [[$product, 4]], $customer);

    $results = runConfirmWorkers([$sale->id, $sale->id], $user->id);

    $exits = array_column($results, 'exit');
    sort($exits);
    expect($exits)->toBe([0, 4], json_encode($results))
        ->and($product->fresh()->stock_on_hand)->toBe(6)
        ->and(StockMovement::query()->where('reference_id', $sale->id)->count())->toBe(1);
});
