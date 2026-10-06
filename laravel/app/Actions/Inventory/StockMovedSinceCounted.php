<?php

namespace App\Actions\Inventory;

use App\Models\StockCount;
use Illuminate\Support\Facades\DB;

/**
 * Counted products with stock movements after their baseline (3.3 review). Applying keeps
 * those movements, which is right when they happened after the shelf was counted; a sale
 * made before counting but keyed in afterwards makes the variance wrong by that amount.
 * The owner reviews these before applying (docs/acceptance-phase3.md 3.10).
 */
class StockMovedSinceCounted
{
    /**
     * @return list<array{product_id: int, sku: string, title: string, sold: int, received: int, other: int, summary: string}>
     */
    public function run(StockCount $count): array
    {
        $rows = DB::table('stock_count_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('stock_movements as m', function ($join) {
                $join->on('m.product_id', '=', 'i.product_id')
                    ->whereRaw('m.id > COALESCE(i.baseline_movement_id, 0)');
            })
            ->where('i.stock_count_id', $count->id)
            ->whereNotNull('i.counted_qty')
            ->where('m.type', '<>', 'count_adjustment')
            ->groupBy('i.product_id', 'p.sku', 'p.title')
            ->orderBy('p.sku')
            ->selectRaw("i.product_id, p.sku, p.title,
                SUM(CASE WHEN m.type IN ('sale_out', 'sale_void_in') THEN -m.quantity ELSE 0 END) as sold,
                SUM(CASE WHEN m.type = 'receipt_in' THEN m.quantity ELSE 0 END) as received,
                SUM(CASE WHEN m.type NOT IN ('sale_out', 'sale_void_in', 'receipt_in') THEN m.quantity ELSE 0 END) as other")
            ->get();

        return $rows->map(function ($r) {
            [$sold, $received, $other] = [(int) $r->sold, (int) $r->received, (int) $r->other];
            $parts = array_filter([
                $sold > 0 ? "{$sold} sold" : ($sold < 0 ? (-$sold).' returned from voided sales' : null),
                $received > 0 ? "{$received} received" : null,
                $other !== 0 ? 'other changes '.($other > 0 ? '+' : '').$other : null,
            ]);

            return [
                'product_id' => (int) $r->product_id,
                'sku' => $r->sku,
                'title' => $r->title,
                'sold' => $sold,
                'received' => $received,
                'other' => $other,
                'summary' => "{$r->sku} {$r->title}: ".($parts === [] ? 'stock moved' : implode(', ', $parts)).' since counted',
            ];
        })->all();
    }
}
