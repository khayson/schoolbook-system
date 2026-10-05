<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Support\ReportPeriods;
use App\Actions\Reports\Support\SaleLines;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales by day, week or month (docs/acceptance-phase3.md 3.1).
 *
 * - Revenue = confirmed sales' subtotal − discount_total, by sale_date.
 * - Collections = valid payments by paid_at (cash received).
 * - With a level, subject or language filter only matching lines count (line revenue);
 *   order discounts and collections cannot be split by catalog and are null.
 */
class SalesSummaryReport
{
    /**
     * @param  array{customer_id?: ?int, level_id?: ?int, subject_id?: ?int, language_id?: ?int}  $filters
     * @return array{from: string, to: string, group_by: string, filters: array, rows: list<array>, totals: array}
     */
    public function run(string $from, string $to, string $groupBy, array $filters = []): array
    {
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        $catalog = array_intersect_key($filters, array_flip(['level_id', 'subject_id', 'language_id'])) !== [];
        [$start, $end] = ReportPeriods::bounds($from, $to);
        $customer = $filters['customer_id'] ?? null;

        $sales = [];
        if ($catalog) {
            foreach (SaleLines::byDay($from, $to, $filters) as $day => $line) {
                $sales[$day] = ['n' => $line['sales'], 'gross' => $line['revenue'], 'disc' => 0];
            }
        } else {
            $rows = DB::table('sales')
                ->where('status', 'confirmed')
                ->where('sale_date', '>=', $start)
                ->where('sale_date', '<', $end)
                ->when($customer, fn (Builder $q, $id) => $q->where('customer_id', $id))
                ->selectRaw('DATE(sale_date) as d, COUNT(*) as n, SUM(subtotal) as gross, SUM(discount_total) as disc')
                ->groupByRaw('DATE(sale_date)')
                ->get();
            foreach ($rows as $r) {
                $sales[(string) $r->d] = ['n' => (int) $r->n, 'gross' => (int) $r->gross, 'disc' => (int) $r->disc];
            }
        }

        $collections = [];
        if (! $catalog) {
            $rows = DB::table('payments')
                ->where('status', 'valid')
                ->where('paid_at', '>=', $start)
                ->where('paid_at', '<', $end)
                ->when($customer, fn (Builder $q, $id) => $q->where('customer_id', $id))
                ->selectRaw('DATE(paid_at) as d, SUM(amount) as amount')
                ->groupByRaw('DATE(paid_at)')
                ->get();
            foreach ($rows as $r) {
                $collections[(string) $r->d] = (int) $r->amount;
            }
        }

        $buckets = array_fill_keys(ReportPeriods::keys($from, $to, $groupBy), ['sales_count' => 0, 'gross' => 0, 'disc' => 0, 'collections' => 0]);
        foreach ($sales as $day => $s) {
            $k = ReportPeriods::key($day, $groupBy);
            $buckets[$k]['sales_count'] += $s['n'];
            $buckets[$k]['gross'] += $s['gross'];
            $buckets[$k]['disc'] += $s['disc'];
        }
        foreach ($collections as $day => $amount) {
            $buckets[ReportPeriods::key($day, $groupBy)]['collections'] += $amount;
        }

        $rows = [];
        foreach ($buckets as $period => $b) {
            $rows[] = $this->figures(['period' => $period], $b, $catalog);
        }
        $totals = $this->figures([], [
            'sales_count' => array_sum(array_column($buckets, 'sales_count')),
            'gross' => array_sum(array_column($buckets, 'gross')),
            'disc' => array_sum(array_column($buckets, 'disc')),
            'collections' => array_sum(array_column($buckets, 'collections')),
        ], $catalog);

        return ['from' => $from, 'to' => $to, 'group_by' => $groupBy, 'filters' => $filters, 'rows' => $rows, 'totals' => $totals];
    }

    private function figures(array $head, array $b, bool $catalog): array
    {
        return $head + [
            'sales_count' => $b['sales_count'],
            'gross' => $b['gross'],
            'order_discounts' => $catalog ? null : $b['disc'],
            'revenue' => $b['gross'] - $b['disc'],
            'collections' => $catalog ? null : $b['collections'],
        ];
    }
}
