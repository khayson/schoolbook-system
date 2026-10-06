<?php

use App\Actions\Inventory\ApplyStockCount;
use App\Actions\Inventory\CancelStockCount;
use App\Actions\Inventory\CreateStockCount;
use App\Actions\Inventory\EnterStockCount;
use App\Actions\Inventory\StockCountTotals;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Exceptions\CountConflictException;
use App\Exceptions\StockCountNotOpenException;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportsFixture;

/*
 * Every literal below is from docs/acceptance-phase3.md 3.10, calculated by hand before
 * the stock-take code existed. Change the code, never these numbers.
 * Start: the end of the reports dataset (A 86, B 44, C 34, D 20, E -1, F 9).
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = ReportsFixture::build($this->owner);
    $this->p = $this->fx->products;
});

afterEach(fn () => Carbon::setTestNow());

function stockTakeAt(string $time): void
{
    Carbon::setTestNow(Carbon::parse("2026-07-01 {$time}", 'Africa/Accra'));
}

/** @param  array<string, int|null>  $counts  product key => counted */
function enterCounts(object $test, StockCount $count, array $counts): StockCount
{
    return app(EnterStockCount::class)->execute($count, array_map(
        fn (string $key) => ['product_id' => $test->p[$key]->id, 'counted_qty' => $counts[$key]],
        array_keys($counts),
    ));
}

function sellAt(object $test, string $time, array $lines): void
{
    stockTakeAt($time);
    $draft = app(CreateDraftSale::class)->execute($test->owner, [
        'customer_id' => $test->fx->customers['alpha']->id,
        'items' => array_map(fn ($l) => ['product_id' => $test->p[$l[0]]->id, 'quantity' => $l[1]], $lines),
    ]);
    app(ConfirmSale::class)->execute($test->owner, $draft, ['due_date' => '2026-07-31']);
}

/** Item fields by SKU letter: [system_qty, counted_qty, variance]. */
function countItems(StockCount $count): array
{
    $rows = [];
    foreach ($count->fresh('items.product')->items->sortBy('product.sku') as $item) {
        $rows[substr($item->product->sku, 4)] = [$item->system_qty, $item->counted_qty, $item->variance];
    }

    return $rows;
}

function stockOf(object $test): array
{
    return array_map(fn ($p) => $p->fresh()->stock_on_hand, $test->p);
}

function countAdjustments(): array
{
    return StockMovement::query()->where('type', 'count_adjustment')->orderBy('product_id')->get()
        ->map(fn ($m) => [substr($m->product->sku, 4), $m->quantity, $m->balance_after])->all();
}

test('K1: count, a sale before apply, a re-entry, apply; then a second apply is refused', function () {
    stockTakeAt('09:00');
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    expect($count->reference)->toBe('CNT-2026-000001')
        ->and($count->status)->toBe('open')
        ->and($count->items)->toHaveCount(6);

    stockTakeAt('10:00');
    enterCounts($this, $count, ['A' => 84, 'B' => 44, 'C' => 30, 'D' => 21, 'F' => 8]);
    expect(countItems($count))->toBe([
        'A' => [86, 84, -2],
        'B' => [44, 44, 0],
        'C' => [34, 30, -4],
        'D' => [20, 21, 1],
        'E' => [null, null, null],
        'F' => [9, 8, -1],
    ]);

    sellAt($this, '11:00', [['C', 2], ['F', 3]]);
    expect(stockOf($this))->toMatchArray(['C' => 32, 'F' => 6]);

    // (3) Re-entering recomputes system_qty, variance and the baseline.
    stockTakeAt('11:30');
    enterCounts($this, $count, ['C' => 31]);
    $cItem = $count->fresh('items')->items->firstWhere('product_id', $this->p['C']->id);
    $cSaleOut = StockMovement::query()->where('product_id', $this->p['C']->id)->where('type', 'sale_out')->max('id');
    expect(countItems($count)['C'])->toBe([32, 31, -1])
        ->and($cItem->baseline_movement_id)->toBe($cSaleOut)
        ->and($cItem->counted_at->format('H:i'))->toBe('11:30');

    stockTakeAt('17:00');
    $before = StockMovement::query()->count();
    $applied = app(ApplyStockCount::class)->execute($this->owner, $count);

    // (1) F keeps the sale: 9 - 3 - 1 = 5. (2) E is untouched. B has no movement (variance 0).
    expect(stockOf($this))->toBe(['A' => 84, 'B' => 44, 'C' => 31, 'D' => 21, 'E' => -1, 'F' => 5])
        ->and(countAdjustments())->toBe([['A', -2, 84], ['C', -1, 31], ['D', 1, 21], ['F', -1, 5]])
        ->and(StockMovement::query()->count())->toBe($before + 4)
        ->and(StockMovement::query()->where('type', 'count_adjustment')->where('reference_type', 'stock_count')->where('reference_id', $count->id)->count())->toBe(4)
        ->and(StockMovement::query()->where('product_id', $this->p['E']->id)->where('type', 'count_adjustment')->exists())->toBeFalse()
        ->and($applied->status)->toBe('applied')
        ->and($applied->applied_at->format('Y-m-d H:i'))->toBe('2026-07-01 17:00')
        ->and($applied->applied_by)->toBe($this->owner->id);

    // (6) Variance at cost.
    expect(app(StockCountTotals::class)->run($applied->fresh()))->toBe([
        'items' => 6, 'counted' => 5, 'variance_units' => -3, 'losses' => 10600, 'gains' => 1800, 'variance_value' => -8800,
    ]);

    // (5) Applying twice is refused, nothing more is written; entries are closed too.
    stockTakeAt('17:05');
    expect(fn () => app(ApplyStockCount::class)->execute($this->owner, $count))->toThrow(StockCountNotOpenException::class)
        ->and(fn () => enterCounts($this, $count, ['E' => 0]))->toThrow(StockCountNotOpenException::class)
        ->and(fn () => app(CancelStockCount::class)->execute($this->owner, $count))->toThrow(StockCountNotOpenException::class)
        ->and(StockMovement::query()->count())->toBe($before + 4)
        ->and(stockOf($this))->toBe(['A' => 84, 'B' => 44, 'C' => 31, 'D' => 21, 'E' => -1, 'F' => 5]);

    $this->artisan('stock:reconcile')->assertSuccessful();
});

test('K2 (4): an apply that would make stock negative is refused whole, nothing written', function () {
    stockTakeAt('09:00');
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    stockTakeAt('10:00');
    enterCounts($this, $count, ['D' => 22, 'F' => 0]);
    expect(countItems($count))->toMatchArray(['D' => [20, 22, 2], 'F' => [9, 0, -9]]);

    sellAt($this, '11:00', [['F', 1]]);

    stockTakeAt('17:00');
    $movements = StockMovement::query()->count();
    try {
        app(ApplyStockCount::class)->execute($this->owner, $count);
        $this->fail('apply should be refused');
    } catch (CountConflictException $e) {
        expect($e->errorCode())->toBe('count_conflict')
            ->and($e->status())->toBe(409)
            ->and($e->details()['items'])->toBe([[
                'product_id' => $this->p['F']->id, 'sku' => 'RPT-F', 'title' => 'Science JHS1 Workbook',
                'stock_on_hand' => 8, 'variance' => -9, 'resulting' => -1,
            ]]);
    }

    expect(stockOf($this))->toMatchArray(['D' => 20, 'F' => 8])
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and(countAdjustments())->toBe([])
        ->and($count->fresh()->status)->toBe('open')
        ->and($count->fresh()->applied_at)->toBeNull()
        ->and($count->fresh('items')->items->whereNotNull('unit_cost'))->toHaveCount(0);

    // The refusal follows the setting: with negative stock allowed the same apply goes through.
    Setting::setValue('allow_negative_stock', true);
    $applied = app(ApplyStockCount::class)->execute($this->owner, $count);

    expect(stockOf($this))->toMatchArray(['D' => 22, 'F' => -1])
        ->and(countAdjustments())->toBe([['D', 2, 22], ['F', -9, -1]])
        ->and(app(StockCountTotals::class)->run($applied->fresh())['variance_value'])->toBe(-5400);
});

test('a filtered count lists only matching active products; inactive products are left out', function () {
    $this->p['D']->update(['is_active' => false]);
    $count = app(CreateStockCount::class)->execute($this->owner, ['filters' => ['subject_id' => $this->p['B']->subject_id]]);

    expect($count->items->map(fn ($i) => $i->product->sku)->sort()->values()->all())->toBe(['RPT-B', 'RPT-F'])
        ->and($count->filters)->toBe(['subject_id' => $this->p['B']->subject_id]);
});

test('clearing an entry makes the item uncounted again; a cancelled count cannot be applied', function () {
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    enterCounts($this, $count, ['A' => 80]);
    enterCounts($this, $count, ['A' => null]);
    expect(countItems($count)['A'])->toBe([null, null, null]);

    app(CancelStockCount::class)->execute($this->owner, $count);
    expect($count->fresh()->status)->toBe('cancelled')
        ->and(fn () => app(ApplyStockCount::class)->execute($this->owner, $count))->toThrow(StockCountNotOpenException::class)
        ->and(countAdjustments())->toBe([]);
});

// --- API -------------------------------------------------------------------------------

test('the stock-take API: create, enter, apply with an Idempotency-Key, second apply 409, sheet', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/v1/stock/counts', ['notes' => 'July count'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'CNT-2026-000001')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonCount(6, 'data.items')
        ->json('data.id');

    $this->putJson("/api/v1/stock/counts/{$id}/items", ['items' => [
        ['product_id' => $this->p['A']->id, 'counted_qty' => 84],
        ['product_id' => $this->p['D']->id, 'counted_qty' => 21],
    ]])->assertOk()
        ->assertJsonPath('data.items.0.sku', 'RPT-A')
        ->assertJsonPath('data.items.0.variance', -2)
        ->assertJsonPath('data.totals.variance_value', -6000 + 1800);

    $this->putJson("/api/v1/stock/counts/{$id}/items", ['items' => [['product_id' => 999999, 'counted_qty' => 1]]])
        ->assertUnprocessable();
    $this->putJson("/api/v1/stock/counts/{$id}/items", ['items' => [['product_id' => $this->p['A']->id, 'counted_qty' => -1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.counted_qty');

    $this->postJson("/api/v1/stock/counts/{$id}/apply")->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_required');

    $first = $this->withHeader('Idempotency-Key', 'apply-1')->postJson("/api/v1/stock/counts/{$id}/apply")
        ->assertOk()->assertJsonPath('data.status', 'applied')->json();
    // A retry with the same key replays; a new key is a second apply: 409.
    $this->withHeader('Idempotency-Key', 'apply-1')->postJson("/api/v1/stock/counts/{$id}/apply")->assertOk()->assertJson($first);
    $this->withHeader('Idempotency-Key', 'apply-2')->postJson("/api/v1/stock/counts/{$id}/apply")
        ->assertStatus(409)->assertJsonPath('code', 'stock_count_not_open')->assertJsonPath('details.status', 'applied');

    expect(countAdjustments())->toBe([['A', -2, 84], ['D', 1, 21]]);

    $this->getJson('/api/v1/stock/counts')->assertOk()->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/v1/stock/counts/{$id}")->assertOk()->assertJsonPath('data.totals.counted', 2);

    $sheet = $this->get("/api/v1/stock/counts/{$id}/sheet")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($sheet->getContent(), 0, 4))->toBe('%PDF');
});

test('count_conflict reaches the client as 409 with the items', function () {
    Sanctum::actingAs($this->owner);
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    enterCounts($this, $count, ['F' => 0]);
    sellAt($this, '11:00', [['F', 1]]);

    $this->withHeader('Idempotency-Key', 'k2')->postJson("/api/v1/stock/counts/{$count->id}/apply")
        ->assertStatus(409)
        ->assertJsonPath('code', 'count_conflict')
        ->assertJsonPath('details.items.0.resulting', -1);
});

test('the count sheet has a blank count column and no system quantities', function () {
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    $count->items->first()->product->update(['title' => 'Maths <b>P4</b>']);

    $html = view('pdf.stock-count-sheet', [
        'count' => $count->fresh(),
        'items' => $count->fresh('items.product.level', 'items.product.subject')->items,
        'business' => ['name' => 'Depot', 'address' => '', 'phone' => '', 'footer' => ''],
    ])->render();

    expect($html)->toContain('CNT-2026-000001')->toContain('RPT-A')->toContain('Counted')
        ->toContain('Maths &lt;b&gt;P4&lt;/b&gt;')
        ->not->toContain('<b>P4')
        ->not->toContain('>86<');
});

test('stock-take is owner only', function () {
    $count = app(CreateStockCount::class)->execute($this->owner, []);
    Sanctum::actingAs(User::factory()->create(['role' => 'school']));

    $this->getJson('/api/v1/stock/counts')->assertForbidden();
    $this->postJson('/api/v1/stock/counts')->assertForbidden();
    $this->putJson("/api/v1/stock/counts/{$count->id}/items", ['items' => [['product_id' => $this->p['A']->id, 'counted_qty' => 1]]])->assertForbidden();
    $this->withHeader('Idempotency-Key', 'x')->postJson("/api/v1/stock/counts/{$count->id}/apply")->assertForbidden();
    expect(DB::table('stock_movements')->where('type', 'count_adjustment')->count())->toBe(0);
});
