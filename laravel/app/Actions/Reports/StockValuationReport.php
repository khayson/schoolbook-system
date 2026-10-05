<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * On-hand stock at current cost and current selling price. Negative stock counts as 0
 * in the value and is reported separately (docs/acceptance-phase3.md 3.4). Deleted
 * products are left out; inactive ones with stock are still on the shelf, so they count.
 */
class StockValuationReport
{
    private const COUNTED = 'CASE WHEN stock_on_hand > 0 THEN stock_on_hand ELSE 0 END';

    /**
     * @return array{rows: list<array>, totals: array}
     */
    public function run(): array
    {
        $counted = self::COUNTED;
        $rows = DB::table('products')
            ->whereNull('deleted_at')
            ->orderBy('sku')
            ->selectRaw("id, sku, title, stock_on_hand, cost_price, selling_price, {$counted} as counted_quantity,
                {$counted} * cost_price as value_at_cost, {$counted} * selling_price as value_at_price")
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'sku' => $r->sku,
                'title' => $r->title,
                'stock_on_hand' => (int) $r->stock_on_hand,
                'counted_quantity' => (int) $r->counted_quantity,
                'cost_price' => (int) $r->cost_price,
                'selling_price' => (int) $r->selling_price,
                'value_at_cost' => (int) $r->value_at_cost,
                'value_at_price' => (int) $r->value_at_price,
            ])
            ->all();

        $t = DB::table('products')
            ->whereNull('deleted_at')
            ->selectRaw("SUM({$counted}) as counted_quantity, SUM({$counted} * cost_price) as value_at_cost, SUM({$counted} * selling_price) as value_at_price,
                SUM(CASE WHEN stock_on_hand < 0 THEN 1 ELSE 0 END) as negative_stock_count,
                SUM(CASE WHEN stock_on_hand < 0 THEN stock_on_hand ELSE 0 END) as negative_stock_units")
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'counted_quantity' => (int) $t->counted_quantity,
                'value_at_cost' => (int) $t->value_at_cost,
                'value_at_price' => (int) $t->value_at_price,
                'negative_stock_count' => (int) $t->negative_stock_count,
                'negative_stock_units' => (int) $t->negative_stock_units,
            ],
        ];
    }
}
