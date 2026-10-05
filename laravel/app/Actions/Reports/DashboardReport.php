<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Support\ReportPeriods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner's dashboard for a date (default today): sales and collections today and month to
 * date; total owed, overdue, customer credit and low-stock count are today's state, with
 * overdue measured against the date; top 5 sellers this month (docs/acceptance-phase3.md 3.8).
 */
class DashboardReport
{
    public function __construct(
        private readonly LowStockReport $lowStock,
        private readonly BestSellersReport $bestSellers,
    ) {}

    public function run(string $date): array
    {
        $day = Carbon::parse($date)->toDateString();
        $monthStart = Carbon::parse($date)->startOfMonth()->toDateString();

        return [
            'date' => $day,
            'sales_today' => $this->sales($day, $day),
            'sales_month' => $this->sales($monthStart, $day),
            'collections_today' => $this->collections($day, $day),
            'collections_month' => $this->collections($monthStart, $day),
            'owed' => (int) DB::table('customers')->sum('outstanding_balance'),
            'overdue' => (int) DB::table('sales')
                ->where('status', 'confirmed')
                ->where('balance_due', '>', 0)
                ->where('due_date', '<', $day)
                ->sum('balance_due'),
            'credit' => (int) DB::table('customers')->sum('credit_balance'),
            'low_stock_count' => $this->lowStock->run()['count'],
            'top_sellers' => array_map(
                fn (array $r) => ['product_id' => $r['key'], 'title' => $r['label'], 'quantity' => $r['quantity'], 'revenue' => $r['revenue']],
                $this->bestSellers->run($monthStart, $day, 'product', 'quantity', 5)['rows'],
            ),
        ];
    }

    /** @return array{count: int, revenue: int} */
    private function sales(string $from, string $to): array
    {
        [$start, $end] = ReportPeriods::bounds($from, $to);
        $r = DB::table('sales')
            ->where('status', 'confirmed')
            ->where('sale_date', '>=', $start)
            ->where('sale_date', '<', $end)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal - discount_total), 0) as revenue')
            ->first();

        return ['count' => (int) $r->n, 'revenue' => (int) $r->revenue];
    }

    private function collections(string $from, string $to): int
    {
        [$start, $end] = ReportPeriods::bounds($from, $to);

        return (int) DB::table('payments')
            ->where('status', 'valid')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->sum('amount');
    }
}
