<?php

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

/*
 * The dataset itself, checked against docs/acceptance-phase3.md section 1 before any
 * report reads it: a report figure can then only be wrong because of the report.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->fx = ReportsFixture::build(User::factory()->owner()->create());
});

test('sales: totals, order discount, balances and statuses are as documented', function () {
    $rows = collect($this->fx->sales)->map(fn ($s) => [
        $s->fresh()->sale_date->toDateString(), $s->subtotal, $s->discount_total, $s->total, $s->fresh()->amount_paid, $s->fresh()->balance_due, $s->fresh()->status->value,
    ])->all();

    expect($rows)->toBe([
        1 => ['2026-02-02', 12000, 0, 12000, 0, 12000, 'confirmed'],
        2 => ['2026-03-20', 12000, 0, 12000, 2000, 10000, 'confirmed'],
        3 => ['2026-04-20', 6000, 0, 6000, 0, 6000, 'confirmed'],
        4 => ['2026-05-20', 4000, 0, 4000, 0, 4000, 'confirmed'],
        5 => ['2026-06-15', 5000, 0, 5000, 0, 5000, 'confirmed'],
        6 => ['2026-06-10', 80000, 4000, 76000, 76000, 0, 'confirmed'],
        7 => ['2026-06-14', 7000, 0, 7000, 0, 7000, 'confirmed'],
        8 => ['2026-06-15', 5000, 0, 5000, 0, 5000, 'confirmed'],
        9 => ['2026-06-16', 12000, 0, 12000, 0, 0, SaleStatus::Void->value],
        10 => ['2026-06-20', 3000, 0, 3000, 3000, 0, 'confirmed'],
    ]);
});

test('customers, payments and stock are as documented', function () {
    $fx = $this->fx;
    $balance = fn (string $c) => Customer::query()->find($fx->customers[$c]->id)->only(['outstanding_balance', 'credit_balance']);
    expect($balance('alpha'))->toBe(['outstanding_balance' => 12000, 'credit_balance' => 0])
        ->and($balance('beta'))->toBe(['outstanding_balance' => 37000, 'credit_balance' => 0])
        ->and($balance('gamma'))->toBe(['outstanding_balance' => 0, 'credit_balance' => 2000]);

    expect(collect($fx->payments)->map(fn ($p) => [$p->fresh()->paid_at->format('Y-m-d H:i'), $p->amount, $p->fresh()->status->value])->all())->toBe([
        'P1' => ['2026-05-10 12:00', 2000, 'valid'],
        'P2' => ['2026-06-12 12:00', 76000, 'valid'],
        'P3' => ['2026-06-21 12:00', 5000, 'valid'],
        'P4' => ['2026-06-25 12:00', 1000, 'void'],
    ]);

    $stock = collect($fx->products)->map(fn ($p) => $p->fresh()->stock_on_hand)->all();
    expect($stock)->toBe(['A' => 86, 'B' => 44, 'C' => 34, 'D' => 20, 'E' => -1, 'F' => 9])
        ->and(StockMovement::query()->where('product_id', $fx->products['F']->id)->where('type', 'sale_out')->value('occurred_at')->toDateTimeString())
        ->toBe('2026-02-02 10:00:00');

    assertMoneyInvariants();
});
