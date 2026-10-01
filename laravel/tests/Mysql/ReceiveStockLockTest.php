<?php

uses()->group('mysql');

use App\Actions\Inventory\ReceiveStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('ReceiveStock times out with 1205 when another connection holds the product lock', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $config = config('database.connections.mysql_testing');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        $config['host'],
        $config['port'],
        $config['database'],
    );

    $holder = new \PDO($dsn, $config['username'], $config['password'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
    $holder->beginTransaction();
    $lock = $holder->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
    $lock->execute([$product->id]);
    expect($lock->fetch(\PDO::FETCH_ASSOC))->not->toBeFalse();

    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $caught = null;
    try {
        app(ReceiveStock::class)->execute($user, [
            'received_at' => '2026-05-01',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000],
            ],
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    $holder->commit();

    expect($caught)->not->toBeNull()
        ->and((int) ($caught->errorInfo[1] ?? 0))->toBe(1205);

    expect($product->fresh()->stock_on_hand)->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('concurrent ReceiveStock workers leave stock equal to the sum', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $worker = base_path('tests/Mysql/bin/receive_stock_worker.php');
    $php = PHP_BINARY;
    $qtyEach = 10;
    $workers = 3;

    $processes = [];
    $pipesList = [];

    for ($i = 0; $i < $workers; $i++) {
        $receivedAt = sprintf('2026-05-%02d', $i + 1);
        $cmd = [
            $php,
            $worker,
            (string) $product->id,
            (string) $user->id,
            (string) $qtyEach,
            '2500',
            $receivedAt,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, base_path());
        expect($proc)->toBeResource();

        fclose($pipes[0]);
        $processes[] = $proc;
        $pipesList[] = $pipes;
    }

    $exitCodes = [];
    $stderr = [];

    foreach ($processes as $index => $proc) {
        $stderr[$index] = stream_get_contents($pipesList[$index][2]);
        fclose($pipesList[$index][1]);
        fclose($pipesList[$index][2]);
        $exitCodes[$index] = proc_close($proc);
    }

    expect($exitCodes)->toBe(array_fill(0, $workers, 0));

    expect($product->fresh()->stock_on_hand)->toBe($qtyEach * $workers);

    $movements = StockMovement::query()
        ->where('product_id', $product->id)
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount($workers)
        ->and($movements->last()->balance_after)->toBe($qtyEach * $workers)
        ->and($movements->pluck('reference_type')->unique()->all())->toBe(['goods_receipt']);
});
