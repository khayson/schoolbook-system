<?php

use App\Actions\Inventory\ReconcileStock;
use App\Console\Commands\ReconcileStockCommand;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ReportsFixture;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->fx = ReportsFixture::build(User::factory()->owner()->create());
    $this->a = $this->fx->products['A'];
    $this->movements = fn () => DB::table('stock_movements')->orderBy('id')->get()->map(fn ($m) => (array) $m)->all();
});

test('the fixture reconciles: real actions keep stock and the balance_after chain consistent', function () {
    expect(app(ReconcileStock::class)->check())->toBe(['stock' => [], 'chain' => []]);

    $this->artisan('stock:reconcile')->expectsOutput('Stock matches the movements.')->assertSuccessful();
});

test('detects a tampered stock_on_hand; --fix repairs it from the movements and leaves movements alone', function () {
    DB::table('products')->where('id', $this->a->id)->update(['stock_on_hand' => 90]);
    $before = ($this->movements)();

    expect(app(ReconcileStock::class)->check()['stock'])->toBe([
        ['product_id' => $this->a->id, 'sku' => 'RPT-A', 'stock_on_hand' => 90, 'movement_total' => 86],
    ]);

    $this->artisan('stock:reconcile')->assertFailed();
    expect($this->a->fresh()->stock_on_hand)->toBe(90); // report-only by default

    $this->artisan('stock:reconcile --fix')->expectsOutput('Repaired stock_on_hand on 1 product(s).')->assertSuccessful();

    expect($this->a->fresh()->stock_on_hand)->toBe(86)
        ->and(($this->movements)())->toBe($before);
});

test('detects a tampered movement quantity: the sum and the chain both break, and --fix cannot clear it', function () {
    $receipt = DB::table('stock_movements')->where('product_id', $this->a->id)->orderBy('id')->first();
    DB::table('stock_movements')->where('id', $receipt->id)->update(['quantity' => 101]);

    $problems = app(ReconcileStock::class)->check();
    expect($problems['stock'])->toBe([
        ['product_id' => $this->a->id, 'sku' => 'RPT-A', 'stock_on_hand' => 86, 'movement_total' => 87],
    ])->and($problems['chain'])->toBe([
        ['movement_id' => $receipt->id, 'product_id' => $this->a->id, 'quantity' => 101, 'balance_after' => 100, 'expected' => 101],
    ]);

    $this->artisan('stock:reconcile --fix')->assertFailed();

    // stock_on_hand now follows the (tampered) sum, the chain break remains and is still reported.
    expect(app(ReconcileStock::class)->check()['chain'])->toHaveCount(1)
        ->and(DB::table('stock_movements')->where('id', $receipt->id)->value('quantity'))->toBe(101);
});

test('detects a tampered balance_after in the middle of the chain', function () {
    $moves = DB::table('stock_movements')->where('product_id', $this->a->id)->orderBy('id')->get();
    $second = $moves[1];
    DB::table('stock_movements')->where('id', $second->id)->update(['balance_after' => $second->balance_after + 5]);

    $chain = app(ReconcileStock::class)->check()['chain'];

    // The edited row no longer follows its predecessor, and its successor no longer follows it.
    expect(array_column($chain, 'movement_id'))->toBe([$second->id, $moves[2]->id])
        ->and(app(ReconcileStock::class)->check()['stock'])->toBe([]);
});

test('--product limits the check', function () {
    DB::table('products')->where('id', $this->a->id)->update(['stock_on_hand' => 90]);

    $this->artisan('stock:reconcile', ['--product' => [$this->fx->products['B']->id]])->assertSuccessful();
    $this->artisan('stock:reconcile', ['--product' => [$this->a->id]])->assertFailed();
});

test('scheduled at 02:45 and a failure is logged as critical', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'stock:reconcile'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('45 2 * * *');

    DB::table('products')->where('id', $this->a->id)->update(['stock_on_hand' => 90]);
    Log::spy();

    ReconcileStockCommand::logScheduledFailure();

    Log::shouldHaveReceived('critical')->once()->withArgs(fn ($message, $context) => $message === 'stock:reconcile found stock problems'
        && $context['stock_mismatches'] === 1 && $context['chain_breaks'] === 0 && $context['products'] === [$this->a->id]);
});
