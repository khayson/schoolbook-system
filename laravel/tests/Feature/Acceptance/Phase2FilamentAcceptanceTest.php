<?php

/**
 * Phase 2 acceptance through the Filament admin (docs/acceptance-phase2.md, part B).
 * Same scenario and amounts as the emulator run (flutter/integration_test), driven
 * through the real Filament pages and actions.
 */

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AcceptanceSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\LevelGroupSeeder;
use Database\Seeders\LevelSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SubjectSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('Phase 2 end to end in Filament: school, bulk order, invoices, instalments, credit, void, reconcile', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Repeater::fake();
    $this->seed([SettingsSeeder::class, LevelGroupSeeder::class, LevelSeeder::class, SubjectSeeder::class, LanguageSeeder::class]);
    $owner = User::factory()->owner()->create();
    $this->seed(AcceptanceSeeder::class);
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $english = Product::query()->where('sku', 'ACC-ENG-P4')->sole();
    $maths = Product::query()->where('sku', 'ACC-MTH-P4')->sole();
    $science = Product::query()->where('sku', 'ACC-SCI-P4')->sole();

    // 2. Create a school
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Acceptance Academy', 'type' => 'school', 'region' => 'Greater Accra', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $school = Customer::query()->where('name', 'Acceptance Academy')->sole();

    $draftAndConfirm = function (array $lines, array $confirm = []) use ($school): Sale {
        Livewire::test(CreateSale::class)
            ->fillForm([
                'customer_id' => $school->id,
                'sale_date' => '2026-10-02',
                'items' => array_map(fn (array $l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $sale = Sale::query()->latest('id')->firstOrFail();

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('confirm', data: $confirm)
            ->assertNotified("Invoice {$sale->fresh()->invoice_no} issued");

        return $sale->fresh();
    };

    // 3-4. Bulk order and a second invoice
    $invoice1 = $draftAndConfirm([[$english, 40], [$maths, 20]]);
    expect($invoice1->total)->toBe(180000)
        ->and($invoice1->invoice_no)->toBe('INV-2026-000001')
        ->and($english->fresh()->stock_on_hand)->toBe(160)
        ->and($maths->fresh()->stock_on_hand)->toBe(80);

    $invoice2 = $draftAndConfirm([[$science, 30]]);
    expect($invoice2->total)->toBe(45000);

    // 5. Instalment 1: GHS 2,000 cash, oldest first across both invoices
    Livewire::test(CreatePayment::class)
        ->fillForm(['customer_id' => $school->id, 'amount' => '2,000', 'method' => 'cash', 'paid_at' => '2026-10-02 09:00:00', 'allocation_mode' => 'oldest'])
        ->call('create')
        ->assertHasNoFormErrors();
    expect($invoice1->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($invoice2->fresh()->balance_due)->toBe(25000)
        ->and(Payment::query()->sole()->allocations()->count())->toBe(2);

    // 6. Instalment 2: GHS 300 MoMo overpays by GHS 50 -> credit
    Livewire::test(EditCustomer::class, ['record' => $school->getRouteKey()])
        ->callAction('recordPayment', data: ['amount' => '300', 'method' => 'momo', 'reference' => 'MP-ACC-1', 'paid_at' => '2026-10-02 09:30:00', 'allocation_mode' => 'oldest'])
        ->assertNotified('Payment RCT-2026-000002 recorded');
    expect($invoice2->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($school->fresh()->credit_balance)->toBe(5000)
        ->and($school->fresh()->outstanding_balance)->toBe(0);

    // 7. Third invoice without credit, then Apply credit
    $invoice3 = $draftAndConfirm([[$english, 4]], ['apply_credit' => false]);
    expect($invoice3->balance_due)->toBe(10000);

    Livewire::test(EditCustomer::class, ['record' => $school->getRouteKey()])
        ->callAction('applyCredit', data: ['mode' => 'oldest'])
        ->assertNotified('Credit applied: GHS 50.00');
    expect($invoice3->fresh()->balance_due)->toBe(5000)
        ->and($school->fresh()->credit_balance)->toBe(0)
        ->and($school->fresh()->outstanding_balance)->toBe(5000);

    // 8. Void the third invoice: everything reverses
    Livewire::test(ViewSale::class, ['record' => $invoice3->getRouteKey()])
        ->callAction('void', data: ['reason' => 'Acceptance: ordered in error'])
        ->assertNotified("Invoice {$invoice3->invoice_no} voided");
    expect($invoice3->fresh()->status)->toBe(SaleStatus::Void)
        ->and($invoice3->fresh()->amount_paid)->toBe(0)
        ->and($school->fresh()->credit_balance)->toBe(5000)
        ->and($school->fresh()->outstanding_balance)->toBe(0)
        ->and($english->fresh()->stock_on_hand)->toBe(160);

    // 9. Reconcile reports clean
    $this->artisan('customers:reconcile')
        ->expectsOutputToContain('All money invariants hold.')
        ->assertSuccessful();

    Carbon::setTestNow();
});
