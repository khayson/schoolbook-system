<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Filament\Pages\Reports\BestSellersPage;
use App\Filament\Pages\Reports\DeadStockPage;
use App\Filament\Pages\Reports\LowStockPage;
use App\Filament\Pages\Reports\ProfitPage;
use App\Filament\Pages\Reports\ReceivablesAgingPage;
use App\Filament\Pages\Reports\SalesSummaryPage;
use App\Filament\Pages\Reports\StockValuationPage;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Resources\StockCounts\RelationManagers\ItemsRelationManager;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\SalesLast30DaysChart;
use App\Filament\Widgets\TopOwingCustomers;
use App\Models\Level;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\ReportsFixture;

/*
 * docs/acceptance-phase3.md figures through the admin (Livewire): report pages, dashboard
 * widgets, the stock-take resource (K1) and the customer statement action.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = ReportsFixture::build($this->owner);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Carbon::setTestNow());

test('3.1 sales summary page: June by week, and the level filter note', function () {
    Livewire::test(SalesSummaryPage::class)
        ->set('filters.from', '2026-06-01')
        ->set('filters.to', '2026-06-30')
        ->set('filters.group_by', 'week')
        ->assertSeeInOrder(['2026-06-08', 'GHS 870.00', 'GHS 40.00', 'GHS 830.00', 'GHS 760.00'])
        ->assertSeeInOrder(['2026-06-15', 'GHS 130.00', 'GHS 130.00', 'GHS 50.00'])
        ->assertSeeInOrder(['GHS 1,000.00', 'GHS 40.00', 'GHS 960.00', 'GHS 810.00'])
        ->set('filters.level_id', Level::query()->where('slug', 'primary-4')->value('id'))
        ->assertSee('line revenue only')
        ->assertSeeHtml('data-total="gross">GHS 700.00</td>')
        ->assertSeeHtml('data-total="order_discounts">—</td>')
        ->assertSeeHtml('data-total="revenue">GHS 700.00</td>')
        ->assertSeeHtml('data-total="collections">—</td>');
});

test('3.2 profit page: June by product, net profit after order discounts', function () {
    Livewire::test(ProfitPage::class)
        ->set('filters.from', '2026-06-01')
        ->set('filters.to', '2026-06-30')
        ->set('filters.group_by', 'product')
        ->assertSeeInOrder(['Maths P4', '12', 'GHS 600.00', 'GHS 360.00', 'GHS 240.00'])
        ->assertSeeInOrder(['Gross profit', 'GHS 395.00', 'Order-level discounts', 'GHS -40.00', 'Net profit', 'GHS 355.00'])
        ->set('filters.from', '2026-01-01')
        ->set('filters.group_by', 'period')
        ->assertSeeInOrder(['2026-02', 'GHS 120.00', 'GHS 70.00', 'GHS 50.00'])
        ->assertSee('GHS 489.00');
});

test('3.3 to 3.7 report pages show the hand-calculated figures', function () {
    Livewire::test(BestSellersPage::class)
        ->set('filters.from', '2026-06-01')->set('filters.to', '2026-06-30')->set('filters.by', 'subject')
        ->assertSeeInOrder(['Mathematics', '20', 'GHS 930.00', 'Science', '2', 'GHS 70.00']);

    Livewire::test(StockValuationPage::class)
        ->assertSeeInOrder(['RPT-E', '-1', '0'])
        ->assertSeeInOrder(['193', 'GHS 5,354.00', 'GHS 8,880.00'])
        ->assertSeeInOrder(['Products with negative stock', '1']);

    Livewire::test(LowStockPage::class)
        ->assertSeeInOrder(['RPT-C', '34', '40', '6', 'Low', 'RPT-A', '86', '90', '4', 'Low', 'RPT-E', '-1', '0', '1', 'Out of stock']);

    Livewire::test(DeadStockPage::class)
        ->set('filters.as_of', '2026-06-30')->set('filters.days', 90)
        ->assertSeeInOrder(['RPT-D', 'Never', 'GHS 360.00', 'RPT-F', '2026-02-02', '148', 'GHS 90.00'])
        ->assertSee('2026-04-01')
        ->set('filters.days', 15)
        ->assertSeeInOrder(['RPT-D', 'RPT-F', 'RPT-C', '2026-06-10', '20', 'RPT-B', '2026-06-14', '16'])
        ->assertSee('GHS 2,774.00');

    Livewire::test(ReceivablesAgingPage::class)
        ->set('filters.as_of', '2026-06-30')
        ->assertSee('Buckets as of 2026-06-30')
        ->assertSeeInOrder(['Alpha School', 'GHS 120.00', 'GHS 0.00', 'GHS 0.00', 'GHS 0.00', 'GHS 0.00', 'GHS 120.00'])
        ->assertSeeInOrder(['Beta School', 'GHS 50.00', 'GHS 40.00', 'GHS 60.00', 'GHS 100.00', 'GHS 120.00', 'GHS 370.00'])
        ->assertSeeInOrder(['GHS 170.00', 'GHS 40.00', 'GHS 60.00', 'GHS 100.00', 'GHS 120.00', 'GHS 490.00'])
        ->assertDontSee('Gamma Academy');
});

test('3.8 dashboard widgets as of 2026-06-30', function () {
    $this->travelTo(Carbon::parse('2026-06-30 18:00', 'Africa/Accra'));

    Livewire::test(DashboardStats::class)
        ->assertSeeInOrder(['Sales today', 'GHS 0.00', '0 sales'])
        ->assertSeeInOrder(['Sales this month', 'GHS 960.00', '5 sales'])
        ->assertSeeInOrder(['Collections today', 'GHS 0.00', 'This month GHS 810.00'])
        ->assertSeeInOrder(['Owed to you', 'GHS 490.00', 'Overdue GHS 320.00'])
        ->assertSeeInOrder(['Customer credit held', 'GHS 20.00'])
        ->assertSeeInOrder(['Low stock', '3']);

    $rows = SalesLast30DaysChart::rows();
    expect($rows)->toHaveCount(30)
        ->and($rows[0]['period'])->toBe('2026-06-01')
        ->and(array_sum(array_column($rows, 'revenue')))->toBe(96000);

    Livewire::test(TopOwingCustomers::class)
        ->assertCanSeeTableRecords([$this->fx->customers['beta'], $this->fx->customers['alpha']], inOrder: true)
        ->assertCanNotSeeTableRecords([$this->fx->customers['gamma']]);

    Livewire::test(LowStockTable::class)
        ->assertCanSeeTableRecords([$this->fx->products['C'], $this->fx->products['A'], $this->fx->products['E']], inOrder: true)
        ->assertCanNotSeeTableRecords([$this->fx->products['B'], $this->fx->products['D'], $this->fx->products['F']]);
});

test('3.10 K1 through the stock-take resource: inline entry, sale, re-entry, apply with the variance value', function () {
    $at = fn (string $t) => Carbon::setTestNow(Carbon::parse("2026-07-01 {$t}", 'Africa/Accra'));
    $p = $this->fx->products;

    $at('09:00');
    Livewire::test(CreateStockCount::class)->fillForm([])->call('create')->assertHasNoFormErrors();
    $count = StockCount::query()->sole();
    expect($count->reference)->toBe('CNT-2026-000001')->and($count->items()->count())->toBe(6);

    $item = fn (string $key) => $count->items()->where('product_id', $p[$key]->id)->first();
    $items = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $count, 'pageClass' => ViewStockCount::class]);

    $at('10:00');
    foreach (['A' => 84, 'B' => 44, 'C' => 30, 'D' => 21, 'F' => 8] as $key => $qty) {
        $items->call('updateTableColumnState', 'counted_qty', (string) $item($key)->getKey(), $qty);
    }
    expect([$item('A')->variance, $item('C')->variance, $item('D')->variance, $item('F')->variance, $item('E')->counted_qty])->toBe([-2, -4, 1, -1, null]);

    $at('11:00');
    $draft = app(CreateDraftSale::class)->execute($this->owner, ['customer_id' => $this->fx->customers['alpha']->id, 'items' => [
        ['product_id' => $p['C']->id, 'quantity' => 2], ['product_id' => $p['F']->id, 'quantity' => 3],
    ]]);
    app(ConfirmSale::class)->execute($this->owner, $draft, ['due_date' => '2026-07-31']);

    $at('11:30');
    $items->call('updateTableColumnState', 'counted_qty', (string) $item('C')->getKey(), 31);
    expect([$item('C')->system_qty, $item('C')->variance])->toBe([32, -1]);

    $items->filterTable('state', 'variance')
        ->assertCanSeeTableRecords([$item('A'), $item('C'), $item('D'), $item('F')])
        ->assertCanNotSeeTableRecords([$item('B'), $item('E')]);

    expect(ViewStockCount::applySummary($count))
        ->toContain('5 of 6 products counted; 4 stock adjustments of -3 units')
        ->toContain('Net variance at cost GHS -88.00 (losses GHS 106.00, gains GHS 18.00)');

    $at('17:00');
    Livewire::test(ViewStockCount::class, ['record' => $count->getRouteKey()])
        ->assertActionVisible('apply')
        ->callAction('apply')
        ->assertNotified('CNT-2026-000001 applied')
        ->assertActionHidden('apply')
        ->assertActionHidden('cancel');

    expect(array_map(fn ($x) => $x->fresh()->stock_on_hand, $p))->toBe(['A' => 84, 'B' => 44, 'C' => 31, 'D' => 21, 'E' => -1, 'F' => 5])
        ->and(StockMovement::query()->where('type', 'count_adjustment')->count())->toBe(4);

    // Entries are closed after apply.
    $items->call('updateTableColumnState', 'counted_qty', (string) $item('E')->getKey(), 0);
    expect($item('E')->counted_qty)->toBeNull();

    Livewire::test(ViewStockCount::class, ['record' => $count->getRouteKey()])
        ->callAction('sheet')
        ->assertFileDownloaded('CNT-2026-000001-sheet.pdf');
});

test('the customer Statement action downloads the PDF for the chosen range', function () {
    $gamma = $this->fx->customers['gamma'];

    Livewire::test(EditCustomer::class, ['record' => $gamma->getRouteKey()])
        ->callAction('statement', data: ['from' => '2026-06-01', 'to' => '2026-06-30'])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded("statement-{$gamma->code}-2026-06-01-2026-06-30.pdf");

    Livewire::test(EditCustomer::class, ['record' => $gamma->getRouteKey()])
        ->callAction('statement', data: ['from' => '2026-06-30', 'to' => '2026-06-01'])
        ->assertHasActionErrors(['to']);
});

test('report pages and the stock-take resource are owner only', function () {
    $this->actingAs(User::factory()->create(['role' => 'school']));

    $this->get(SalesSummaryPage::getUrl())->assertForbidden();
    $this->get('/admin/stock-counts')->assertForbidden();
});
