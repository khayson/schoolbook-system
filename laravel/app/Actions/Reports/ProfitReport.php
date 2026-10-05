<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Support\ReportPeriods;
use App\Actions\Reports\Support\SaleLines;
use Illuminate\Support\Facades\DB;

/**
 * Gross profit per line = line_total − unit_cost × quantity (snapshot cost), grouped by
 * product, level, subject, language or period. Order-level discounts belong to the
 * whole order, so they are one separate line: net profit = gross − order discounts
 * (docs/acceptance-phase3.md 3.2).
 */
class ProfitReport
{
    public const GROUPINGS = ['product', 'level', 'subject', 'language', 'period'];

    /**
     * @return array{from: string, to: string, group_by: string, rows: list<array>, totals: array}
     */
    public function run(string $from, string $to, string $groupBy, string $period = 'month'): array
    {
        if ($groupBy === 'period') {
            $buckets = array_fill_keys(ReportPeriods::keys($from, $to, $period), ['quantity' => 0, 'revenue' => 0, 'cost' => 0]);
            foreach (SaleLines::byDay($from, $to) as $day => $line) {
                $k = ReportPeriods::key($day, $period);
                foreach (['quantity', 'revenue', 'cost'] as $f) {
                    $buckets[$k][$f] += $line[$f];
                }
            }
            $rows = [];
            foreach ($buckets as $key => $b) {
                $rows[] = ['key' => $key, 'label' => $key] + $this->profit($b);
            }
        } else {
            $rows = array_map(
                fn (array $r) => array_intersect_key($r, array_flip(['key', 'label', 'sku'])) + $this->profit($r),
                SaleLines::byDimension($from, $to, $groupBy),
            );
            usort($rows, fn ($a, $b) => [$b['gross_profit'], $a['label']] <=> [$a['gross_profit'], $b['label']]);
        }

        [$start, $end] = ReportPeriods::bounds($from, $to);
        $orderDiscounts = (int) DB::table('sales')
            ->where('status', 'confirmed')
            ->where('sale_date', '>=', $start)
            ->where('sale_date', '<', $end)
            ->sum('discount_total');

        $gross = array_sum(array_column($rows, 'gross_profit'));

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'rows' => $rows,
            'totals' => [
                'quantity' => array_sum(array_column($rows, 'quantity')),
                'revenue' => array_sum(array_column($rows, 'revenue')),
                'cost' => array_sum(array_column($rows, 'cost')),
                'gross_profit' => $gross,
                'order_level_discounts' => $orderDiscounts,
                'net_profit' => $gross - $orderDiscounts,
            ],
        ];
    }

    private function profit(array $r): array
    {
        return [
            'quantity' => $r['quantity'],
            'revenue' => $r['revenue'],
            'cost' => $r['cost'],
            'gross_profit' => $r['revenue'] - $r['cost'],
        ];
    }
}
