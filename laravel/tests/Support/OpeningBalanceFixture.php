<?php

namespace Tests\Support;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ImportCustomersFromCsv;
use App\Actions\Inventory\ReceiveStock;
use App\Actions\Payments\RecordPayment;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * docs/acceptance-phase3.md section 6: the customers import with opening balances, then
 * a sale and a payment. Expects the lookups seeded (DatabaseSeeder). Test databases only.
 */
final class OpeningBalanceFixture
{
    /** The import file of 6.2 (row numbers count the header as row 1). */
    public const CSV = <<<'CSV'
        code,name,type,region,district,address,contact_person,phone,email,credit_limit,opening_balance,opening_balance_date,opening_balance_due,notes
        ,Kasoa Hilltop School,school,Central,Awutu Senya East Municipal,Kasoa,Mr Annan,0244000001,,5000.00,"2,500.00",2026-08-31,2026-09-30,
        ,Winneba Bright Stars,school,Central,Effutu Municipal,Winneba,,0244000002,,,1200.00,2026-08-31,2026-10-15,
        ,Osu Little Angels,school,Greater Accra,Korle Klottey Municipal,Osu,,0244000003,,,,,,
        ,Existing Academy,school,Greater Accra,,,,024 400 0004,,,300.00,2026-08-31,2026-09-30,
        ,No Region School,school,,,,,0244000005,,,100.00,2026-08-31,2026-09-30,
        ,Bad Amount School,school,Central,,,,0244000006,,,12.345,2026-08-31,2026-09-30,
        ,Kasoa Hilltop School,school,Central,,,,0244000001,,,999.00,2026-08-31,2026-09-30,
        CSV;

    public Product $a;

    /** @var array<string, Customer> hilltop, bright, osu, existing */
    public array $customers = [];

    public function __construct(public readonly User $owner) {}

    /** The import file without the two rows that have errors (rows 6 and 7). */
    public static function fixedCsv(): string
    {
        return implode("\n", array_values(array_filter(
            explode("\n", self::CSV),
            fn (string $line) => ! str_contains($line, 'No Region School') && ! str_contains($line, 'Bad Amount School'),
        )));
    }

    public static function existingAcademy(): Customer
    {
        return app(CreateCustomer::class)->execute([
            'name' => 'Existing Academy', 'type' => 'school', 'region' => 'Greater Accra', 'phone' => '0244000004',
        ]);
    }

    public static function build(User $owner): self
    {
        $fx = new self($owner);
        Setting::setValue('allow_negative_stock', false);
        try {
            $fx->customers['existing'] = self::existingAcademy();

            self::at('2026-08-01 09:00');
            $fx->a = Product::query()->create([
                'sku' => 'OB-A', 'title' => 'Maths P4',
                'level_id' => Level::query()->where('slug', 'primary-4')->value('id'),
                'subject_id' => Subject::query()->where('slug', 'mathematics')->value('id'),
                'language_id' => Language::query()->where('code', 'en')->value('id'),
                'cost_price' => 3000, 'selling_price' => 5000, 'reorder_level' => 0, 'is_active' => true,
            ]);
            app(ReceiveStock::class)->execute($owner, ['received_at' => now(), 'items' => [['product_id' => $fx->a->id, 'quantity' => 10, 'unit_cost' => 3000]]]);

            self::at('2026-09-01 08:00');
            app(ImportCustomersFromCsv::class)->commit($owner, self::fixedCsv());
            foreach (['hilltop' => 'Kasoa Hilltop School', 'bright' => 'Winneba Bright Stars', 'osu' => 'Osu Little Angels'] as $key => $name) {
                $fx->customers[$key] = Customer::query()->where('name', $name)->sole();
            }

            self::at('2026-09-10 10:00');
            $draft = app(CreateDraftSale::class)->execute($owner, ['customer_id' => $fx->customers['hilltop']->id, 'items' => [['product_id' => $fx->a->id, 'quantity' => 2]]]);
            app(ConfirmSale::class)->execute($owner, $draft, ['due_date' => '2026-10-10']);

            self::at('2026-09-20 12:00');
            app(RecordPayment::class)->execute($owner, ['customer_id' => $fx->customers['hilltop']->id, 'amount' => 100000, 'method' => 'cash', 'paid_at' => now()]);
        } finally {
            Carbon::setTestNow();
        }

        return $fx;
    }

    public static function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'Africa/Accra'));
    }
}
