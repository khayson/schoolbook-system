<?php

use App\Actions\Inventory\CreateStockCount;
use App\Actions\Inventory\EnterStockCount;
use App\Actions\Inventory\ReceiveStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
 * 3.3 review: system_qty and baseline_movement_id must describe the same moment. Under
 * InnoDB REPEATABLE READ a plain SELECT reads the transaction's snapshot, taken at its
 * first plain read; the product row is read with FOR UPDATE, which sees the latest
 * commit. A movement committed in between used to give a fresh system_qty with an older
 * baseline. The latest movement id is now a locking read too.
 */

test('a movement committed after the snapshot is both in system_qty and in the baseline', function () {
    $this->seed(DatabaseSeeder::class);
    $owner = User::factory()->owner()->create();
    $product = Product::factory()->create(['cost_price' => 1000, 'selling_price' => 2000, 'is_active' => true]);
    app(ReceiveStock::class)->execute($owner, ['received_at' => now(), 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]]]);
    $count = app(CreateStockCount::class)->execute($owner, ['filters' => []]);
    $receipt = StockMovement::query()->where('product_id', $product->id)->value('id');

    $config = config('database.connections.mysql_testing');
    $other = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    DB::beginTransaction();
    try {
        // The transaction's snapshot is taken here, before the other connection's sale.
        expect(StockMovement::query()->where('product_id', $product->id)->count())->toBe(1);

        // A sale of 3 committed by another connection (autocommit).
        $other->prepare("INSERT INTO stock_movements (product_id, type, quantity, balance_after, user_id, occurred_at, created_at) VALUES (?, 'sale_out', -3, 7, ?, NOW(), NOW())")
            ->execute([$product->id, $owner->id]);
        $sale = (int) $other->lastInsertId();
        $other->prepare('UPDATE products SET stock_on_hand = 7 WHERE id = ?')->execute([$product->id]);

        app(EnterStockCount::class)->execute($count, [['product_id' => $product->id, 'counted_qty' => 6]]);
        $item = $count->items()->where('product_id', $product->id)->first();

        expect($sale)->toBeGreaterThan($receipt)
            ->and([$item->system_qty, $item->variance, $item->baseline_movement_id])->toBe([7, -1, $sale]);
    } finally {
        DB::rollBack();
    }
});
