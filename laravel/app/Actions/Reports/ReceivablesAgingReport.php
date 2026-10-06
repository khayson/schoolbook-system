<?php

namespace App\Actions\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money owed per customer by days past due on the as-of date: not yet due (≤ 0),
 * 1–30, 31–60, 61–90, 90+. Confirmed sales with balance_due > 0, today's balances
 * (the as-of date only moves the buckets). The total equals the sum of customers'
 * outstanding_balance (docs/acceptance-phase3.md 3.7).
 */
class ReceivablesAgingReport
{
    public const BUCKETS = ['not_yet_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    /**
     * @return array{as_of: string, rows: list<array>, totals: array}
     */
    public function run(string $asOf): array
    {
        $d = Carbon::parse($asOf)->startOfDay();
        // Days past due = as_of − due_date, so "1–30 days" is as_of − 30 ≤ due < as_of, etc.
        $at = fn (int $daysBack) => $d->copy()->subDays($daysBack)->toDateString();
        $case = 'SUM(CASE WHEN %s THEN s.balance_due ELSE 0 END) as %s';
        $select = implode(', ', [
            sprintf($case, 's.due_date IS NULL OR s.due_date >= ?', 'not_yet_due'),
            sprintf($case, 's.due_date < ? AND s.due_date >= ?', 'days_1_30'),
            sprintf($case, 's.due_date < ? AND s.due_date >= ?', 'days_31_60'),
            sprintf($case, 's.due_date < ? AND s.due_date >= ?', 'days_61_90'),
            sprintf($case, 's.due_date < ?', 'days_90_plus'),
            'SUM(s.balance_due) as total',
            // How much of the total is debt brought forward from before the system.
            'SUM(CASE WHEN s.is_opening_balance = 1 THEN s.balance_due ELSE 0 END) as brought_forward',
        ]);

        $rows = DB::table('sales as s')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.status', 'confirmed')
            ->where('s.balance_due', '>', 0)
            ->groupBy('c.id', 'c.name')
            ->selectRaw("c.id as customer_id, c.name, {$select}", [
                $at(0),
                $at(0), $at(30),
                $at(30), $at(60),
                $at(60), $at(90),
                $at(90),
            ])
            ->orderBy('c.name')
            ->get()
            ->map(fn ($r) => ['customer_id' => (int) $r->customer_id, 'name' => $r->name]
                + array_map('intval', array_intersect_key((array) $r, array_flip([...self::BUCKETS, 'total', 'brought_forward']))))
            ->map(fn (array $r) => array_merge(array_flip(['customer_id', 'name', ...self::BUCKETS, 'total', 'brought_forward']), $r))
            ->all();

        $totals = [];
        foreach ([...self::BUCKETS, 'total', 'brought_forward'] as $b) {
            $totals[$b] = array_sum(array_column($rows, $b));
        }

        return ['as_of' => $d->toDateString(), 'rows' => $rows, 'totals' => $totals];
    }
}
