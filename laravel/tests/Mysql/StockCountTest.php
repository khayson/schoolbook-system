<?php

use App\Actions\Inventory\ApplyStockCount;
use App\Actions\Inventory\CreateStockCount;
use App\Actions\Inventory\EnterStockCount;
use App\Actions\Inventory\StockCountTotals;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Exceptions\CountConflictException;
use App\Exceptions\StockCountNotOpenException;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\ReportsFixture;

/*
 * docs/acceptance-phase3.md 3.10 on MySQL (schoolbook_test): row locks, the refused
 * apply rolling back on InnoDB, and the CNT sequence.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = ReportsFixture::build($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

test('stock-take K1 and K2 figures on MySQL', function () {
    $p = $this->fx->products;
    $time = fn (string $t) => Carbon::setTestNow(Carbon::parse("2026-07-01 {$t}", 'Africa/Accra'));
    $enter = fn ($count, array $counts) => app(EnterStockCount::class)->execute($count, array_map(fn ($k) => ['product_id' => $p[$k]->id, 'counted_qty' => $counts[$k]], array_keys($counts)));
    $sell = function (string $t, array $lines) use ($p, $time) {
        $time($t);
        $draft = app(CreateDraftSale::class)->execute($this->owner, ['customer_id' => $this->fx->customers['alpha']->id, 'items' => array_map(fn ($l) => ['product_id' => $p[$l[0]]->id, 'quantity' => $l[1]], $lines)]);
        app(ConfirmSale::class)->execute($this->owner, $draft, ['due_date' => '2026-07-31']);
    };
    $stock = fn () => array_map(fn ($x) => $x->fresh()->stock_on_hand, $p);

    // K2 first: refused whole, nothing written.
    $time('09:00');
    $k2 = app(CreateStockCount::class)->execute($this->owner, []);
    $time('10:00');
    $enter($k2, ['D' => 22, 'F' => 0]);
    $sell('11:00', [['F', 1]]);
    $time('17:00');
    $movements = StockMovement::query()->count();
    expect(fn () => app(ApplyStockCount::class)->execute($this->owner, $k2))->toThrow(CountConflictException::class)
        ->and($stock())->toMatchArray(['D' => 20, 'F' => 8])
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and($k2->fresh()->status)->toBe('open');

    // K1 on the fixture state (F is 8 now, after K2's sale): counted values follow 3.10 K1.
    $k2->update(['status' => 'cancelled']);
    $time('09:00');
    $k1 = app(CreateStockCount::class)->execute($this->owner, []);
    expect($k1->reference)->toBe('CNT-2026-000002');
    $time('10:00');
    $enter($k1, ['A' => 84, 'B' => 44, 'C' => 30, 'D' => 21, 'F' => 7]);
    $sell('11:00', [['C', 2], ['F', 3]]);
    $time('11:30');
    $enter($k1, ['C' => 31]);
    $time('17:00');
    $applied = app(ApplyStockCount::class)->execute($this->owner, $k1);

    // F: 8 counted 7 (variance -1), then 3 sold: 8 - 3 - 1 = 4.
    expect($stock())->toBe(['A' => 84, 'B' => 44, 'C' => 31, 'D' => 21, 'E' => -1, 'F' => 4])
        ->and(app(StockCountTotals::class)->run($applied->fresh()))->toBe([
            'items' => 6, 'counted' => 5, 'variance_units' => -3, 'losses' => 10600, 'gains' => 1800, 'variance_value' => -8800,
        ])
        ->and(fn () => app(ApplyStockCount::class)->execute($this->owner, $k1))->toThrow(StockCountNotOpenException::class);

    $this->artisan('stock:reconcile')->assertSuccessful();
});
