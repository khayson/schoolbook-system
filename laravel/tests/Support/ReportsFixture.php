<?php

namespace Tests\Support;

use App\Actions\Inventory\ReceiveStock;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\VoidPayment;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\VoidSale;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;

/**
 * The Phase 3 reports dataset (docs/acceptance-phase3.md, section 1), built with the
 * real actions. Expects the lookups seeded (DatabaseSeeder). Test databases only.
 */
final class ReportsFixture
{
    /** @var array<string, Product> A..F */
    public array $products = [];

    /** @var array<string, Customer> alpha, beta, gamma */
    public array $customers = [];

    /** @var array<int, Sale> 1..10 */
    public array $sales = [];

    /** @var array<string, Payment> P1..P4 */
    public array $payments = [];

    public function __construct(public readonly User $owner) {}

    public static function build(User $owner): self
    {
        $fixture = new self($owner);
        app()->instance(PricingService::class, new Phase4StandInPricing);
        Setting::setValue('allow_negative_stock', false);
        Setting::setValue('default_payment_terms_days', 30);

        try {
            $fixture->catalog();
            $fixture->sales();
            $fixture->payments();
        } finally {
            Carbon::setTestNow();
            app()->forgetInstance(PricingService::class);
        }

        return $fixture;
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'Africa/Accra'));
    }

    private function catalog(): void
    {
        $p4 = Level::query()->where('slug', 'primary-4')->value('id');
        $j1 = Level::query()->where('slug', 'jhs-1')->value('id');
        $maths = Subject::query()->where('slug', 'mathematics')->value('id');
        $science = Subject::query()->where('slug', 'science')->value('id');
        $english = Language::query()->where('code', 'en')->value('id');

        $rows = [
            'A' => ['Maths P4', $p4, $maths, 3000, 5000, 90, 100],
            'B' => ['Science P4', $p4, $science, 2500, 4000, 10, 50],
            'C' => ['Maths JHS1', $j1, $maths, 3600, 6000, 40, 40],
            'D' => ['Science JHS1', $j1, $science, 1800, 3000, 5, 20],
            'E' => ['Maths P4 Workbook', $p4, $maths, 500, 1000, 0, 2],
            'F' => ['Science JHS1 Workbook', $j1, $science, 1000, 2000, 0, 10],
        ];
        $items = [];
        foreach ($rows as $key => [$title, $level, $subject, $cost, $price, $reorder, $received]) {
            $this->products[$key] = Product::query()->create([
                'sku' => 'RPT-'.$key,
                'title' => $title,
                'level_id' => $level,
                'subject_id' => $subject,
                'language_id' => $english,
                'cost_price' => $cost,
                'selling_price' => $price,
                'reorder_level' => $reorder,
                'is_active' => true,
            ]);
            $items[] = ['product_id' => $this->products[$key]->id, 'quantity' => $received, 'unit_cost' => $cost];
        }

        $this->at('2026-01-05 09:00');
        app(ReceiveStock::class)->execute($this->owner, ['received_at' => now(), 'items' => $items]);

        $this->customers = [
            'alpha' => Customer::factory()->create(['name' => 'Alpha School', 'credit_limit' => null]),
            'beta' => Customer::factory()->create(['name' => 'Beta School', 'credit_limit' => null]),
            'gamma' => Customer::factory()->create(['name' => 'Gamma Academy', 'credit_limit' => null]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: int, 2?: int, 3?: string}>  $lines  [product key, qty, override price, reason]
     */
    private function sell(int $n, string $customer, string $moment, array $lines, string $due): void
    {
        $this->at($moment);
        $draft = app(CreateDraftSale::class)->execute($this->owner, [
            'customer_id' => $this->customers[$customer]->id,
            'items' => array_map(fn (array $l) => array_filter([
                'product_id' => $this->products[$l[0]]->id,
                'quantity' => $l[1],
                'override_unit_price' => $l[2] ?? null,
                'override_reason' => $l[3] ?? null,
            ], fn ($v) => $v !== null), $lines),
        ]);
        $this->sales[$n] = app(ConfirmSale::class)->execute($this->owner, $draft, ['due_date' => $due]);
    }

    private function sales(): void
    {
        $this->sell(1, 'beta', '2026-02-02 10:00', [['A', 2], ['F', 1]], '2026-03-01');
        $this->sell(2, 'beta', '2026-03-20 10:00', [['B', 3]], '2026-04-20');
        $this->sell(3, 'beta', '2026-04-20 10:00', [['C', 1]], '2026-05-20');
        $this->sell(4, 'beta', '2026-05-20 10:00', [['B', 1]], '2026-06-20');
        $this->sell(6, 'alpha', '2026-06-10 11:00', [['A', 10], ['C', 5]], '2026-07-10');
        $this->sell(7, 'alpha', '2026-06-14 23:30', [['B', 2, 3500, 'promotion']], '2026-07-14');
        $this->sell(8, 'alpha', '2026-06-15 00:30', [['A', 1]], '2026-07-15');
        $this->sell(5, 'beta', '2026-06-15 10:00', [['A', 1]], '2026-07-15');
        $this->sell(9, 'alpha', '2026-06-16 09:00', [['C', 2]], '2026-07-16');

        $this->at('2026-06-16 15:00');
        $this->sales[9] = app(VoidSale::class)->execute($this->owner, $this->sales[9], 'Entered for the wrong school');

        Setting::setValue('allow_negative_stock', true);
        $this->sell(10, 'gamma', '2026-06-20 10:00', [['E', 3]], '2026-07-20');
        Setting::setValue('allow_negative_stock', false);

        ksort($this->sales);
    }

    private function pay(string $key, string $customer, string $moment, int $amount, array $extra = []): void
    {
        $this->at($moment);
        $this->payments[$key] = app(RecordPayment::class)->execute($this->owner, [
            'customer_id' => $this->customers[$customer]->id,
            'amount' => $amount,
            'method' => 'cash',
            'paid_at' => now(),
            ...$extra,
        ]);
    }

    /**
     * Payments are recorded in date order, interleaved with the sales above by their
     * own timestamps (P1 in May, P2-P4 in June); allocation only depends on which
     * invoices are open, which the order below reproduces.
     */
    private function payments(): void
    {
        $this->pay('P1', 'beta', '2026-05-10 12:00', 2000, [
            'auto_allocate' => false,
            'allocations' => [['sale_id' => $this->sales[2]->id, 'amount' => 2000]],
        ]);
        $this->pay('P2', 'alpha', '2026-06-12 12:00', 76000, [
            'auto_allocate' => false,
            'allocations' => [['sale_id' => $this->sales[6]->id, 'amount' => 76000]],
        ]);
        $this->pay('P3', 'gamma', '2026-06-21 12:00', 5000);
        $this->pay('P4', 'alpha', '2026-06-25 12:00', 1000);

        $this->at('2026-06-25 13:00');
        $this->payments['P4'] = app(VoidPayment::class)->execute($this->owner, $this->payments['P4'], 'Recorded twice');
    }
}
