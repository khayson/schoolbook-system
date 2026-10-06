<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * stock:reconcile (spec 8): stock_on_hand must equal the sum of the product's movements,
 * and every movement's balance_after must equal the previous movement's balance_after
 * (by id, per product; 0 before the first) plus its quantity. Set-based SQL; the chain
 * uses a window function (MySQL 8, SQLite 3.25+). Repair only ever rewrites
 * stock_on_hand, under the product lock; movements are immutable and never touched.
 */
class ReconcileStock
{
    /**
     * @param  list<int>|null  $productIds
     * @return array{stock: list<array{product_id: int, sku: string, stock_on_hand: int, movement_total: int}>, chain: list<array{movement_id: int, product_id: int, quantity: int, balance_after: int, expected: int}>}
     */
    public function check(?array $productIds = null): array
    {
        $totals = DB::table('stock_movements')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total');

        $stock = DB::table('products as p')
            ->leftJoinSub($totals, 'm', 'm.product_id', '=', 'p.id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('p.id', $productIds))
            ->whereRaw('p.stock_on_hand <> COALESCE(m.total, 0)')
            ->orderBy('p.id')
            ->selectRaw('p.id, p.sku, p.stock_on_hand, COALESCE(m.total, 0) as movement_total')
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'sku' => $r->sku,
                'stock_on_hand' => (int) $r->stock_on_hand,
                'movement_total' => (int) $r->movement_total,
            ])
            ->all();

        $withPrevious = DB::table('stock_movements')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->selectRaw('id, product_id, quantity, balance_after, LAG(balance_after, 1, 0) OVER (PARTITION BY product_id ORDER BY id) as previous_balance');

        $chain = DB::query()->fromSub($withPrevious, 'c')
            ->whereRaw('balance_after <> previous_balance + quantity')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'movement_id' => (int) $r->id,
                'product_id' => (int) $r->product_id,
                'quantity' => (int) $r->quantity,
                'balance_after' => (int) $r->balance_after,
                'expected' => (int) $r->previous_balance + (int) $r->quantity,
            ])
            ->all();

        return ['stock' => $stock, 'chain' => $chain];
    }

    /**
     * Sets stock_on_hand to the sum of movements for the given products, locked in
     * ascending id order and re-summed under the lock. Returns the products changed.
     *
     * @param  list<int>  $productIds
     */
    public function repairStockOnHand(array $productIds): int
    {
        $ids = array_values(array_unique($productIds));
        sort($ids);

        return DB::transaction(function () use ($ids) {
            $changed = 0;
            foreach ($ids as $id) {
                $product = Product::withTrashed()->lockForUpdate()->find($id);
                if ($product === null) {
                    continue;
                }
                $total = (int) DB::table('stock_movements')->where('product_id', $id)->sum('quantity');
                if ($product->stock_on_hand === $total) {
                    continue;
                }
                DB::table('products')->where('id', $id)->update(['stock_on_hand' => $total]);
                Log::warning('stock:reconcile repaired stock_on_hand', [
                    'product_id' => $id,
                    'from' => $product->stock_on_hand,
                    'to' => $total,
                ]);
                $changed++;
            }

            return $changed;
        });
    }
}
