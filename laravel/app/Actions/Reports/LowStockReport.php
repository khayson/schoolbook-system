<?php

namespace App\Actions\Reports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active products at or below their reorder level; shortfall = reorder level − stock;
 * status out_of_stock when stock ≤ 0, otherwise low (docs/acceptance-phase3.md 3.5).
 */
class LowStockReport
{
    /** The low-stock products (active, not deleted, stock at or below the reorder level). */
    public static function products(): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->whereColumn('stock_on_hand', '<=', 'reorder_level');
    }

    /**
     * @return array{rows: list<array>, count: int}
     */
    public function run(): array
    {
        $rows = self::products()->toBase()
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
                'status' => (int) $r->stock_on_hand <= 0 ? 'out_of_stock' : 'low',
            ])
            ->all();

        return ['rows' => $rows, 'count' => count($rows)];
    }
}
