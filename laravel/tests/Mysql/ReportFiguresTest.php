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
use App\Models\Level;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

/*
 * The docs/acceptance-phase3.md figures on MySQL (schoolbook_test): DATE columns,
 * DECIMAL sums and string/date comparisons behave differently from SQLite.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->fx = ReportsFixture::build(User::factory()->owner()->create());
});

test('every report gives the hand-calculated figures on MySQL', function () {
    $summary = app(SalesSummaryReport::class);
    expect($summary->run('2026-06-01', '2026-06-30', 'day')['totals'])
        ->toBe(['sales_count' => 5, 'gross' => 100000, 'order_discounts' => 4000, 'revenue' => 96000, 'collections' => 81000])
        ->and(array_map(fn ($r) => [$r['period'], $r['revenue'], $r['collections']], $summary->run('2026-06-01', '2026-06-30', 'week')['rows']))
        ->toBe([['2026-06-01', 0, 0], ['2026-06-08', 83000, 76000], ['2026-06-15', 13000, 5000], ['2026-06-22', 0, 0], ['2026-06-29', 0, 0]])
        ->and($summary->run('2026-01-01', '2026-06-30', 'month')['totals']['revenue'])->toBe(130000)
        ->and($summary->run('2026-06-01', '2026-06-30', 'month', ['level_id' => Level::query()->where('slug', 'primary-4')->value('id')])['totals']['gross'])->toBe(70000);

    expect(app(ProfitReport::class)->run('2026-06-01', '2026-06-30', 'product')['totals'])
        ->toBe(['quantity' => 22, 'revenue' => 100000, 'cost' => 60500, 'gross_profit' => 39500, 'order_level_discounts' => 4000, 'net_profit' => 35500])
        ->and(app(ProfitReport::class)->run('2026-01-01', '2026-06-30', 'period')['totals']['net_profit'])->toBe(48900);

    expect(array_map(fn ($r) => [$r['label'], $r['quantity']], app(BestSellersReport::class)->run('2026-06-01', '2026-06-30', 'subject')['rows']))
        ->toBe([['Mathematics', 20], ['Science', 2]]);

    expect(app(StockValuationReport::class)->run()['totals'])
        ->toBe(['counted_quantity' => 193, 'value_at_cost' => 535400, 'value_at_price' => 888000, 'negative_stock_count' => 1, 'negative_stock_units' => -1])
        ->and(array_column(app(LowStockReport::class)->run()['rows'], 'sku'))->toBe(['RPT-C', 'RPT-A', 'RPT-E']);

    $dead = app(DeadStockReport::class)->run('2026-06-30', 90);
    expect(array_map(fn ($r) => [$r['sku'], $r['last_sold_at'], $r['days_since_sale']], $dead['rows']))
        ->toBe([['RPT-D', null, null], ['RPT-F', '2026-02-02', 148]]);

    $aging = app(ReceivablesAgingReport::class)->run('2026-06-30');
    expect($aging['totals'])->toBe(['not_yet_due' => 17000, 'days_1_30' => 4000, 'days_31_60' => 6000, 'days_61_90' => 10000, 'days_90_plus' => 12000, 'total' => 49000, 'brought_forward' => 0])
        ->and($aging['totals']['total'])->toBe((int) Customer::query()->sum('outstanding_balance'));
    $beta = fn (string $asOf) => collect(app(ReceivablesAgingReport::class)->run($asOf)['rows'])->firstWhere('name', 'Beta School');
    expect([$beta('2026-07-20')['days_1_30'], $beta('2026-07-20')['days_90_plus'], $beta('2026-07-21')['days_31_60']])->toBe([9000, 22000, 4000]);

    expect(app(DashboardReport::class)->run('2026-06-15'))->toMatchArray([
        'sales_today' => ['count' => 2, 'revenue' => 10000],
        'sales_month' => ['count' => 4, 'revenue' => 93000],
        'collections_month' => 76000,
        'owed' => 49000,
        'overdue' => 28000,
        'credit' => 2000,
        'low_stock_count' => 3,
    ]);
});
