<?php

namespace App\Actions\Reports;

use App\Enums\SaleStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Active products with stock that have not sold in the `days` before the as-of date:
 * no sale_out movement on or after as_of âˆ’ days belonging to a sale that is still
 * confirmed. A voided sale sold nothing, so its sale_out does not count
 * (docs/acceptance-phase3.md 3.6).
 */
class DeadStockReport
{
    /**
     * @return array{as_of: string, days: int, cutoff: string, rows: list<array>, totals: array}
     */
    public function run(string $asOf, int $days = 90): array
    {
        $asOfDate = Carbon::parse($asOf)->startOfDay();
        $cutoff = $asOfDate->copy()->subDays($days)->toDateString();
        $until = $asOfDate->copy()->addDay()->toDateString();

        $lastSale = DB::table('stock_movements as sm')
            ->join('sales as s', fn ($join) => $join->on('s.id', '=', 'sm.reference_id')->where('sm.reference_type', '=', 'sale'))
            ->where('sm.type', 'sale_out')
            ->where('s.status', SaleStatus::Confirmed->value)
            ->where('sm.occurred_at', '<', $until)
            ->groupBy('sm.product_id')
            ->selectRaw('sm.product_id, MAX(sm.occurred_at) as last_sold_at');

        $rows = DB::table('products as p')
            ->leftJoinSub($lastSale, 'm', 'm.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('p.stock_on_hand', '>', 0)
            ->where(fn ($q) => $q->whereNull('m.last_sold_at')->orWhere('m.last_sold_at', '<', $cutoff))
            ->selectRaw('p.id, p.sku, p.title, p.stock_on_hand, p.cost_price, p.stock_on_hand * p.cost_price as value_at_cost, m.last_sold_at')
            ->orderByRaw('CASE WHEN m.last_sold_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('m.last_sold_at')
            ->orderBy('p.sku')
            ->get()
            ->map(function ($r) use ($asOfDate) {
                $last = $r->last_sold_at === null ? null : Carbon::parse($r->last_sold_at);

                return [
                    'product_id' => (int) $r->id,
                    'sku' => $r->sku,
                    'title' => $r->title,
                    'stock_on_hand' => (int) $r->stock_on_hand,
                    'last_sold_at' => $last?->toDateString(),
                    'days_since_sale' => $last === null ? null : (int) $last->copy()->startOfDay()->diffInDays($asOfDate),
                    'value_at_cost' => (int) $r->value_at_cost,
                ];
            })
            ->all();

        return [
            'as_of' => $asOfDate->toDateString(),
            'days' => $days,
            'cutoff' => $cutoff,
            'rows' => $rows,
            'totals' => ['count' => count($rows), 'value_at_cost' => array_sum(array_column($rows, 'value_at_cost'))],
        ];
    }
}
