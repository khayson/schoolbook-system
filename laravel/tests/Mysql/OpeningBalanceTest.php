<?php

use App\Actions\Customers\CustomerStatement;
use App\Actions\Reports\ReceivablesAgingReport;
use App\Actions\Reports\SalesSummaryReport;
use App\Actions\Sales\CreateOpeningBalance;
use App\Actions\Sales\VoidSale;
use App\Exceptions\OpeningBalanceExistsException;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Tests\Support\OpeningBalanceFixture;

/*
 * docs/acceptance-phase3.md section 6 on MySQL (schoolbook_test): the stored generated
 * column and its unique index, and the figures through MySQL's SQL.
 */

afterEach(fn () => Carbon::setTestNow());

test('opening balances on MySQL: figures, the unique index, and voiding releases it', function () {
    $this->seed(DatabaseSeeder::class);
    $owner = User::factory()->owner()->create();
    $fx = OpeningBalanceFixture::build($owner);

    $statement = app(CustomerStatement::class)->run($fx->customers['hilltop']->fresh(), '2026-08-01', '2026-11-05');
    expect(array_column($statement['lines'], 'balance'))->toBe([250000, 260000, 160000])
        ->and($statement['lines'][0]['description'])->toBe('Balance brought forward (OB-2026-000001)')
        ->and(app(ReceivablesAgingReport::class)->run('2026-11-05')['totals'])
        ->toMatchArray(['days_1_30' => 130000, 'days_31_60' => 150000, 'total' => 280000, 'brought_forward' => 270000])
        ->and(app(SalesSummaryReport::class)->run('2026-08-01', '2026-09-30', 'month')['totals']['revenue'])->toBe(10000);

    // The stored column carries the customer while live; the index refuses a second one.
    expect(Sale::query()->where('invoice_no', 'OB-2026-000001')->value('opening_balance_customer_id'))->toBe($fx->customers['hilltop']->id);
    $copy = Sale::query()->where('invoice_no', 'OB-2026-000001')->sole()->replicate(['opening_balance_customer_id']);
    $copy->invoice_no = 'OB-TEST';
    expect(fn () => $copy->save())->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => app(CreateOpeningBalance::class)->execute($owner, $fx->customers['hilltop'], ['amount' => 100, 'date' => '2026-09-01', 'due_date' => '2026-10-01']))
        ->toThrow(OpeningBalanceExistsException::class);

    OpeningBalanceFixture::at('2026-09-25 09:00');
    app(VoidSale::class)->execute($owner, Sale::query()->where('invoice_no', 'OB-2026-000002')->sole(), 'Wrong amount');
    expect(Sale::query()->where('invoice_no', 'OB-2026-000002')->value('opening_balance_customer_id'))->toBeNull()
        ->and(app(CreateOpeningBalance::class)->execute($owner, $fx->customers['bright'], ['amount' => 80000, 'date' => '2026-08-31', 'due_date' => '2026-10-15'])->invoice_no)
        ->toBe('OB-2026-000004');

    $this->artisan('customers:reconcile')->assertSuccessful();
});
