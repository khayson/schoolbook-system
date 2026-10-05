<?php

use App\Actions\Reports\BestSellersReport;
use App\Actions\Reports\DashboardReport;
use App\Actions\Reports\DeadStockReport;
use App\Actions\Reports\LowStockReport;
use App\Actions\Reports\ProfitReport;
use App\Actions\Reports\ReceivablesAgingReport;
use App\Actions\Reports\SalesSummaryReport;
use App\Actions\Reports\StockValuationReport;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Level;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

/*
 * Every literal below is from docs/acceptance-phase3.md section 3, calculated by hand
 * before the report code existed. Change the code, never these numbers.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->fx = ReportsFixture::build(User::factory()->owner()->create());
});

/** Rows keyed by period, non-zero rows only, selected fields. */
function nonZero(array $rows, array $fields): array
{
    $out = [];
    foreach ($rows as $row) {
        $values = array_map(fn ($f) => $row[$f], $fields);
        if (array_filter($values, fn ($v) => $v !== 0 && $v !== null) !== []) {
            $out[$row['period']] = $values;
        }
    }

    return $out;
}

// --- 3.1 Sales summary ---------------------------------------------------------------

test('3.1 sales summary June by day: totals, the six active days, 30 rows', function () {
    $r = app(SalesSummaryReport::class)->run('2026-06-01', '2026-06-30', 'day');

    expect($r['totals'])->toBe(['sales_count' => 5, 'gross' => 100000, 'order_discounts' => 4000, 'revenue' => 96000, 'collections' => 81000])
        ->and($r['rows'])->toHaveCount(30)
        ->and(nonZero($r['rows'], ['sales_count', 'gross', 'order_discounts', 'revenue', 'collections']))->toBe([
            '2026-06-10' => [1, 80000, 4000, 76000, 0],
            '2026-06-12' => [0, 0, 0, 0, 76000],
            '2026-06-14' => [1, 7000, 0, 7000, 0],
            '2026-06-15' => [2, 10000, 0, 10000, 0],
            '2026-06-20' => [1, 3000, 0, 3000, 0],
            '2026-06-21' => [0, 0, 0, 0, 5000],
        ]);
});

test('3.1 sales summary June by week, with the 23:30 / 00:30 boundary between weeks', function () {
    $r = app(SalesSummaryReport::class)->run('2026-06-01', '2026-06-30', 'week');

    expect(array_map(fn ($row) => [$row['period'], $row['sales_count'], $row['revenue'], $row['collections']], $r['rows']))->toBe([
        ['2026-06-01', 0, 0, 0],
        ['2026-06-08', 2, 83000, 76000],
        ['2026-06-15', 3, 13000, 5000],
        ['2026-06-22', 0, 0, 0],
        ['2026-06-29', 0, 0, 0],
    ]);
});

test('3.1 sales summary January to June by month', function () {
    $r = app(SalesSummaryReport::class)->run('2026-01-01', '2026-06-30', 'month');

    expect(array_map(fn ($row) => [$row['period'], $row['sales_count'], $row['revenue'], $row['collections']], $r['rows']))->toBe([
        ['2026-01', 0, 0, 0],
        ['2026-02', 1, 12000, 0],
        ['2026-03', 1, 12000, 0],
        ['2026-04', 1, 6000, 0],
        ['2026-05', 1, 4000, 2000],
        ['2026-06', 5, 96000, 81000],
    ])
        ->and($r['totals'])->toBe(['sales_count' => 9, 'gross' => 134000, 'order_discounts' => 4000, 'revenue' => 130000, 'collections' => 83000]);
});

test('3.1 sales summary filters: customer, level, subject, language', function () {
    $fx = $this->fx;
    $level = fn (string $slug) => Level::query()->where('slug', $slug)->value('id');
    $subject = fn (string $slug) => Subject::query()->where('slug', $slug)->value('id');
    $report = app(SalesSummaryReport::class);

    expect($report->run('2026-06-01', '2026-06-30', 'month', ['customer_id' => $fx->customers['alpha']->id])['totals'])
        ->toBe(['sales_count' => 3, 'gross' => 92000, 'order_discounts' => 4000, 'revenue' => 88000, 'collections' => 76000])
        ->and($report->run('2026-06-01', '2026-06-30', 'month', ['level_id' => $level('primary-4')])['totals'])
        ->toBe(['sales_count' => 5, 'gross' => 70000, 'order_discounts' => null, 'revenue' => 70000, 'collections' => null])
        ->and($report->run('2026-06-01', '2026-06-30', 'month', ['subject_id' => $subject('science')])['totals'])
        ->toBe(['sales_count' => 1, 'gross' => 7000, 'order_discounts' => null, 'revenue' => 7000, 'collections' => null])
        ->and($report->run('2026-06-01', '2026-06-30', 'month', ['language_id' => Language::query()->where('code', 'en')->value('id')])['totals'])
        ->toBe(['sales_count' => 5, 'gross' => 100000, 'order_discounts' => null, 'revenue' => 100000, 'collections' => null]);
});

// --- 3.2 Profit ----------------------------------------------------------------------------

test('3.2 profit June by product, with the order-level discount as its own line', function () {
    $r = app(ProfitReport::class)->run('2026-06-01', '2026-06-30', 'product');

    expect(array_map(fn ($row) => [$row['label'], $row['quantity'], $row['revenue'], $row['cost'], $row['gross_profit']], $r['rows']))->toBe([
        ['Maths P4', 12, 60000, 36000, 24000],
        ['Maths JHS1', 5, 30000, 18000, 12000],
        ['Science P4', 2, 7000, 5000, 2000],
        ['Maths P4 Workbook', 3, 3000, 1500, 1500],
    ])
        ->and($r['totals'])->toBe([
            'quantity' => 22, 'revenue' => 100000, 'cost' => 60500, 'gross_profit' => 39500,
            'order_level_discounts' => 4000, 'net_profit' => 35500,
        ]);
});

test('3.2 profit June by level, subject and language', function () {
    $report = app(ProfitReport::class);
    $rows = fn (string $by) => array_map(
        fn ($row) => [$row['label'], $row['revenue'], $row['cost'], $row['gross_profit']],
        $report->run('2026-06-01', '2026-06-30', $by)['rows'],
    );

    expect($rows('level'))->toBe([['Primary 4', 70000, 42500, 27500], ['JHS 1', 30000, 18000, 12000]])
        ->and($rows('subject'))->toBe([['Mathematics', 93000, 55500, 37500], ['Science', 7000, 5000, 2000]])
        ->and($rows('language'))->toBe([['English', 100000, 60500, 39500]])
        ->and($report->run('2026-06-01', '2026-06-30', 'subject')['totals']['net_profit'])->toBe(35500);
});

test('3.2 profit January to June by month', function () {
    $r = app(ProfitReport::class)->run('2026-01-01', '2026-06-30', 'period', 'month');

    expect(array_map(fn ($row) => [$row['label'], $row['revenue'], $row['cost'], $row['gross_profit']], $r['rows']))->toBe([
        ['2026-01', 0, 0, 0],
        ['2026-02', 12000, 7000, 5000],
        ['2026-03', 12000, 7500, 4500],
        ['2026-04', 6000, 3600, 2400],
        ['2026-05', 4000, 2500, 1500],
        ['2026-06', 100000, 60500, 39500],
    ])
        ->and($r['totals'])->toMatchArray(['revenue' => 134000, 'cost' => 81100, 'gross_profit' => 52900, 'order_level_discounts' => 4000, 'net_profit' => 48900]);
});

// --- 3.3 Best sellers ------------------------------------------------------------------

test('3.3 best sellers June by quantity and by revenue, and by level, subject, language', function () {
    $report = app(BestSellersReport::class);
    $rows = fn (string $by, string $sort = 'quantity') => array_map(
        fn ($row) => [$row['label'], $row['quantity'], $row['revenue']],
        $report->run('2026-06-01', '2026-06-30', $by, $sort)['rows'],
    );

    expect($rows('product'))->toBe([['Maths P4', 12, 60000], ['Maths JHS1', 5, 30000], ['Maths P4 Workbook', 3, 3000], ['Science P4', 2, 7000]])
        ->and($rows('product', 'revenue'))->toBe([['Maths P4', 12, 60000], ['Maths JHS1', 5, 30000], ['Science P4', 2, 7000], ['Maths P4 Workbook', 3, 3000]])
        ->and($rows('level'))->toBe([['Primary 4', 17, 70000], ['JHS 1', 5, 30000]])
        ->and($rows('subject'))->toBe([['Mathematics', 20, 93000], ['Science', 2, 7000]])
        ->and($rows('language'))->toBe([['English', 22, 100000]]);
});

// --- 3.4 to 3.6 Stock --------------------------------------------------------------------

test('3.4 stock valuation: product rows, totals equal to their sum, negative stock counted apart', function () {
    $r = app(StockValuationReport::class)->run();

    expect(array_map(fn ($row) => [$row['sku'], $row['stock_on_hand'], $row['counted_quantity'], $row['value_at_cost'], $row['value_at_price']], $r['rows']))->toBe([
        ['RPT-A', 86, 86, 258000, 430000],
        ['RPT-B', 44, 44, 110000, 176000],
        ['RPT-C', 34, 34, 122400, 204000],
        ['RPT-D', 20, 20, 36000, 60000],
        ['RPT-E', -1, 0, 0, 0],
        ['RPT-F', 9, 9, 9000, 18000],
    ])
        ->and($r['totals'])->toBe(['counted_quantity' => 193, 'value_at_cost' => 535400, 'value_at_price' => 888000, 'negative_stock_count' => 1, 'negative_stock_units' => -1])
        ->and($r['totals']['value_at_cost'])->toBe(array_sum(array_column($r['rows'], 'value_at_cost')))
        ->and($r['totals']['value_at_price'])->toBe(array_sum(array_column($r['rows'], 'value_at_price')));
});

test('3.5 low stock: C, A, E by shortfall', function () {
    $r = app(LowStockReport::class)->run();

    expect(array_map(fn ($row) => [$row['sku'], $row['stock_on_hand'], $row['reorder_level'], $row['shortfall']], $r['rows']))->toBe([
        ['RPT-C', 34, 40, 6],
        ['RPT-A', 86, 90, 4],
        ['RPT-E', -1, 0, 1],
    ])->and($r['count'])->toBe(3);
});

test('3.6 dead stock as of 2026-06-30: D never sold, F last sold 148 days before; 150 days leaves D', function () {
    $r = app(DeadStockReport::class)->run('2026-06-30', 90);

    expect($r['cutoff'])->toBe('2026-04-01')
        ->and(array_map(fn ($row) => [$row['sku'], $row['stock_on_hand'], $row['last_sold_at'], $row['days_since_sale'], $row['value_at_cost']], $r['rows']))->toBe([
            ['RPT-D', 20, null, null, 36000],
            ['RPT-F', 9, '2026-02-02', 148, 9000],
        ])
        ->and($r['totals'])->toBe(['count' => 2, 'value_at_cost' => 45000]);

    $longer = app(DeadStockReport::class)->run('2026-06-30', 150);
    expect($longer['cutoff'])->toBe('2026-01-31')
        ->and(array_column($longer['rows'], 'sku'))->toBe(['RPT-D']);
});

// --- 3.7 Receivables aging ---------------------------------------------------------------

test('3.7 receivables aging as of 2026-06-30: buckets per customer; total = sum of outstanding balances', function () {
    $r = app(ReceivablesAgingReport::class)->run('2026-06-30');
    $buckets = ['not_yet_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', 'total'];

    expect(array_map(fn ($row) => [$row['name'], ...array_map(fn ($b) => $row[$b], $buckets)], $r['rows']))->toBe([
        ['Alpha School', 12000, 0, 0, 0, 0, 12000],
        ['Beta School', 5000, 4000, 6000, 10000, 12000, 37000],
    ])
        ->and($r['totals'])->toBe(['not_yet_due' => 17000, 'days_1_30' => 4000, 'days_31_60' => 6000, 'days_61_90' => 10000, 'days_90_plus' => 12000, 'total' => 49000])
        ->and($r['totals']['total'])->toBe((int) Customer::query()->sum('outstanding_balance'));
});

// --- 3.8 Dashboard -------------------------------------------------------------------------

test('3.8 dashboard as of 2026-06-30', function () {
    $r = app(DashboardReport::class)->run('2026-06-30');

    expect($r)->toMatchArray([
        'date' => '2026-06-30',
        'sales_today' => ['count' => 0, 'revenue' => 0],
        'sales_month' => ['count' => 5, 'revenue' => 96000],
        'collections_today' => 0,
        'collections_month' => 81000,
        'owed' => 49000,
        'overdue' => 32000,
        'credit' => 2000,
        'low_stock_count' => 3,
    ])
        ->and(array_map(fn ($t) => [$t['title'], $t['quantity']], $r['top_sellers']))
        ->toBe([['Maths P4', 12], ['Maths JHS1', 5], ['Maths P4 Workbook', 3], ['Science P4', 2]]);
});

test('3.8 dashboard as of 2026-06-15: today and month to date; 23:30 the night before is not today', function () {
    $r = app(DashboardReport::class)->run('2026-06-15');

    expect($r)->toMatchArray([
        'sales_today' => ['count' => 2, 'revenue' => 10000],
        'sales_month' => ['count' => 4, 'revenue' => 93000],
        'collections_today' => 0,
        'collections_month' => 76000,
        'overdue' => 28000,
        'owed' => 49000,
        'credit' => 2000,
    ]);
});

// --- Invariants ----------------------------------------------------------------------------

test('voided sale 9 and voided payment P4 are in no figure', function () {
    // Sale 9 was C x 2 (12,000) on 2026-06-16; P4 was 1,000 on 2026-06-25.
    $day = app(SalesSummaryReport::class)->run('2026-06-16', '2026-06-25', 'day');
    expect($day['totals'])->toBe(['sales_count' => 1, 'gross' => 3000, 'order_discounts' => 0, 'revenue' => 3000, 'collections' => 5000]);

    $c = collect(app(BestSellersReport::class)->run('2026-06-16', '2026-06-16', 'product')['rows']);
    expect($c)->toBeEmpty();
});

test('3.7 aging bucket boundaries: 0/1, 30/31, 60/61 and 90/91 days past due (Beta row)', function () {
    $beta = fn (string $asOf) => collect(app(ReceivablesAgingReport::class)->run($asOf)['rows'])->firstWhere('name', 'Beta School');
    $buckets = fn (array $row) => [$row['not_yet_due'], $row['days_1_30'], $row['days_31_60'], $row['days_61_90'], $row['days_90_plus'], $row['total']];

    expect($buckets($beta('2026-06-20')))->toBe([9000, 0, 6000, 10000, 12000, 37000])
        ->and($buckets($beta('2026-06-21')))->toBe([5000, 4000, 6000, 10000, 12000, 37000])
        ->and($buckets($beta('2026-07-19')))->toBe([0, 9000, 6000, 10000, 12000, 37000])
        ->and($buckets($beta('2026-07-20')))->toBe([0, 9000, 0, 6000, 22000, 37000])
        ->and($buckets($beta('2026-07-21')))->toBe([0, 5000, 4000, 6000, 22000, 37000]);
});
