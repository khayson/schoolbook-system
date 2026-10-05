<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Active products at or below their reorder level; shortfall = reorder level − stock
 * (docs/acceptance-phase3.md 3.5).
 */
class LowStockReport
{
    /**
     * @return array{rows: list<array>, count: int}
     */
    public function run(): array
    {
        $rows = DB::table('products')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereColumn('stock_on_hand', '<=', 'reorder_level')
            ->selectRaw('id, sku, title, stock_on_hand, reorder_level, reorder_level - stock_on_hand as shortfall')
            ->orderByDesc('shortfall')
            ->orderBy('title')
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'sku' => $r->sku,
                'title' => $r->title,
                'stock_on_hand' => (int) $r->stock_on_hand,
                'reorder_level' => (int) $r->reorder_level,
                'shortfall' => (int) $r->shortfall,
            ])
            ->all();

        return ['rows' => $rows, 'count' => count($rows)];
    }
}
