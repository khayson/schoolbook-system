<?php

namespace App\Actions\Inventory;

use App\Models\StockCount;

/** Variance totals of a stock count (docs/acceptance-phase3.md 3.10 (6)). */
class StockCountTotals
{
    /**
     * Counted items and variance totals. Value uses the cost stored at apply, or the
     * current cost price while the count is open.
     *
     * @return array{items: int, counted: int, variance_units: int, losses: int, gains: int, variance_value: int}
     */
    public function run(StockCount $count): array
    {
        $count->loadMissing('items.product');
        $units = $losses = $gains = 0;
        $counted = 0;
        foreach ($count->items as $item) {
            if ($item->counted_qty === null) {
                continue;
            }
            $counted++;
            $units += $item->variance;
            $value = $item->variance * ($item->unit_cost ?? (int) $item->product->cost_price);
            $value < 0 ? $losses -= $value : $gains += $value;
        }

        return [
            'items' => $count->items->count(),
            'counted' => $counted,
            'variance_units' => $units,
            'losses' => $losses,
            'gains' => $gains,
            'variance_value' => $gains - $losses,
        ];
    }
}
