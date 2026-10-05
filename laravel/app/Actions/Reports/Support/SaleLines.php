<?php

namespace App\Actions\Reports\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Line-level aggregates of confirmed sales in a date range (profit, best sellers,
 * filtered sales summary). One GROUP BY query per call; catalog grouping uses each
 * product's current level, subject and language.
 */
final class SaleLines
{
    public const DIMENSIONS = ['product', 'level', 'subject', 'language'];

    public static function base(string $from, string $to, array $filters = []): Builder
    {
        [$start, $end] = ReportPeriods::bounds($from, $to);

        return DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('s.status', 'confirmed')
            ->where('s.sale_date', '>=', $start)
            ->where('s.sale_date', '<', $end)
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('s.customer_id', $id))
            ->when($filters['level_id'] ?? null, fn (Builder $q, $id) => $q->where('p.level_id', $id))
            ->when($filters['subject_id'] ?? null, fn (Builder $q, $id) => $q->where('p.subject_id', $id))
            ->when($filters['language_id'] ?? null, fn (Builder $q, $id) => $q->where('p.language_id', $id));
    }

    /**
     * Per catalog dimension: key, label, quantity, revenue (line totals), cost (snapshot).
     *
     * @return list<array{key: int, label: string, sku?: string, quantity: int, revenue: int, cost: int}>
     */
    public static function byDimension(string $from, string $to, string $dimension): array
    {
        $query = self::base($from, $to);
        [$keyColumn, $labelColumn] = match ($dimension) {
            'product' => ['p.id', 'p.title'],
            'level' => ['l.id', 'l.name'],
            'subject' => ['sj.id', 'sj.name'],
            'language' => ['lg.id', 'lg.name'],
            default => throw new InvalidArgumentException("Unknown dimension [{$dimension}]."),
        };
        match ($dimension) {
            'level' => $query->join('levels as l', 'l.id', '=', 'p.level_id'),
            'subject' => $query->join('subjects as sj', 'sj.id', '=', 'p.subject_id'),
            'language' => $query->join('languages as lg', 'lg.id', '=', 'p.language_id'),
            default => null,
        };

        $rows = $query
            ->selectRaw("{$keyColumn} as k, {$labelColumn} as label".($dimension === 'product' ? ', p.sku as sku' : ''))
            ->selectRaw('SUM(i.quantity) as quantity, SUM(i.line_total) as revenue, SUM(i.unit_cost * i.quantity) as cost')
            ->groupBy(array_filter([$keyColumn, $labelColumn, $dimension === 'product' ? 'p.sku' : null]))
            ->get();

        return $rows->map(fn ($r) => array_filter([
            'key' => (int) $r->k,
            'label' => (string) $r->label,
            'sku' => $r->sku ?? null,
            'quantity' => (int) $r->quantity,
            'revenue' => (int) $r->revenue,
            'cost' => (int) $r->cost,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Per day (sale_date): quantity, revenue, cost, and the number of sales with lines in scope.
     *
     * @return array<string, array{sales: int, quantity: int, revenue: int, cost: int}>
     */
    public static function byDay(string $from, string $to, array $filters = []): array
    {
        $rows = self::base($from, $to, $filters)
            ->selectRaw('DATE(s.sale_date) as d, COUNT(DISTINCT s.id) as sales, SUM(i.quantity) as quantity, SUM(i.line_total) as revenue, SUM(i.unit_cost * i.quantity) as cost')
            ->groupByRaw('DATE(s.sale_date)')
            ->get();

        $days = [];
        foreach ($rows as $r) {
            $days[(string) $r->d] = ['sales' => (int) $r->sales, 'quantity' => (int) $r->quantity, 'revenue' => (int) $r->revenue, 'cost' => (int) $r->cost];
        }

        return $days;
    }
}
