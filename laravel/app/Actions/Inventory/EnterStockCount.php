<?php

namespace App\Actions\Inventory;

use App\Exceptions\InvalidInputException;
use App\Exceptions\StockCountNotOpenException;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Records counted quantities on an open count (spec 6.3; docs/acceptance-phase3.md 3.10).
 * Each entry snapshots, under the product lock, system_qty = the product's balance at
 * that moment, baseline_movement_id = its latest movement, variance = counted − system.
 * Re-entering recomputes; null clears. Locks: the count row, then products by id.
 */
class EnterStockCount
{
    /**
     * Records counted quantities (null clears an entry). Each entry snapshots the
     * product's current balance and latest movement, so re-entering recomputes.
     *
     * @param  list<array{product_id: int, counted_qty: int|null}>  $entries
     */
    public function execute(StockCount $count, array $entries): StockCount
    {
        return DB::transaction(function () use ($count, $entries) {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new StockCountNotOpenException($locked, 'enter');
            }

            $items = $locked->items()->get()->keyBy('product_id');
            $unknown = array_values(array_diff(array_map(fn ($e) => (int) $e['product_id'], $entries), $items->keys()->all()));
            if ($unknown !== []) {
                throw new InvalidInputException('items', 'Products not in this count: '.implode(', ', $unknown).'.');
            }

            $ordered = collect($entries)->keyBy(fn ($e) => (int) $e['product_id'])->sortKeys();
            foreach ($ordered as $productId => $entry) {
                $item = $items[$productId];
                $counted = $entry['counted_qty'];

                if ($counted === null) {
                    $item->update(['counted_qty' => null, 'system_qty' => null, 'variance' => null, 'baseline_movement_id' => null, 'counted_at' => null]);

                    continue;
                }

                // Both reads are locking reads, so they see the latest commit rather than
                // this transaction's snapshot (InnoDB REPEATABLE READ): every movement
                // writer holds this product lock, so balance and latest movement pair up.
                $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->firstOrFail();
                $system = (int) $product->stock_on_hand;
                $baseline = StockMovement::query()->where('product_id', $productId)->lockForUpdate()->max('id');

                $item->update([
                    'counted_qty' => (int) $counted,
                    'system_qty' => $system,
                    'variance' => (int) $counted - $system,
                    'baseline_movement_id' => $baseline,
                    'counted_at' => now(),
                ]);
            }

            return $locked->load('items.product');
        });
    }
}
