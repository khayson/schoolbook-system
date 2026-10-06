<?php

use App\Actions\Customers\CustomerStatement;
use App\Actions\Inventory\ReconcileStock;
use App\Actions\Reports\DeadStockReport;
use App\Actions\Reports\LowStockReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportsFixture;

/*
 * docs/acceptance-phase3.md 3.5, 3.6 and 3.9 on MySQL (schoolbook_test): the statement's
 * UNION ALL with signed casts over unsigned money columns, and stock:reconcile's window
 * function.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->fx = ReportsFixture::build(User::factory()->owner()->create());
});

test('statements, 3.2 report changes and stock:reconcile on MySQL', function () {
    $run = fn (string $c, string $from, string $to) => app(CustomerStatement::class)->run($this->fx->customers[$c]->fresh(), $from, $to);
    $totals = fn (array $s) => [$s['opening_balance'], $s['totals']['debits'], $s['totals']['credits'], $s['closing_balance']];

    $alpha = $run('alpha', '2026-06-01', '2026-06-30');
    expect(array_map(fn ($l) => [$l['type'], $l['amount'], $l['balance']], $alpha['lines']))->toBe([
        ['invoice', 76000, 76000], ['payment', -76000, 0], ['invoice', 7000, 7000], ['invoice', 5000, 12000],
        ['invoice', 12000, 24000], ['invoice_void', -12000, 12000], ['payment', -1000, 11000], ['payment_void', 1000, 12000],
    ])->and($totals($alpha))->toBe([0, 101000, 89000, 12000])
        ->and($totals($run('beta', '2026-01-01', '2026-06-30')))->toBe([0, 39000, 2000, 37000])
        ->and($totals($run('gamma', '2026-06-21', '2026-06-30')))->toBe([3000, 0, 5000, -2000])
        ->and($totals($run('alpha', '2026-06-15', '2026-06-15')))->toBe([7000, 5000, 0, 12000]);

    foreach ($this->fx->customers as $customer) {
        $customer->refresh();
        expect($run(array_search($customer, $this->fx->customers, true), '2026-01-01', '2026-12-31')['closing_balance'])
            ->toBe($customer->outstanding_balance - $customer->credit_balance);
    }

    expect(array_column(app(DeadStockReport::class)->run('2026-06-30', 15)['rows'], 'sku'))->toBe(['RPT-D', 'RPT-F', 'RPT-C', 'RPT-B'])
        ->and(array_column(app(LowStockReport::class)->run()['rows'], 'status'))->toBe(['low', 'low', 'out_of_stock']);

    expect(app(ReconcileStock::class)->check())->toBe(['stock' => [], 'chain' => []]);
    $a = $this->fx->products['A'];
    $first = DB::table('stock_movements')->where('product_id', $a->id)->orderBy('id')->value('id');
    DB::table('stock_movements')->where('id', $first)->update(['balance_after' => 99]);
    DB::table('products')->where('id', $a->id)->update(['stock_on_hand' => 80]);

    $problems = app(ReconcileStock::class)->check();
    expect($problems['stock'])->toBe([['product_id' => $a->id, 'sku' => 'RPT-A', 'stock_on_hand' => 80, 'movement_total' => 86]])
        ->and(array_column($problems['chain'], 'expected'))->toBe([100, 97]);
});
