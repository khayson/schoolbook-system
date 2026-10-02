<?php

use App\Actions\Sales\ConfirmSale;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    Setting::setValue('business_name', 'Kumasi Book Depot');
    Setting::setValue('business_address', "12 Adum Road\nKumasi");
    Setting::setValue('business_phone', '0244000000');
    Setting::setValue('invoice_footer', 'MoMo 0244000000. Thank you.');

    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

afterEach(function () {
    // Every scenario must leave the money caches consistent with the ledger.
    assertMoneyInvariants();
    Carbon::setTestNow();
});

// --- Invoice PDF ----------------------------------------------------------------------------

test('invoice pdf downloads for a confirmed sale', function () {
    $product = stockedProduct(stock: 1000, price: 123450);
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 10]], $customer));

    $response = $this->withToken($this->token)->get("/api/v1/sales/{$sale->id}/invoice")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('INV-2026-000001.pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

test('invoice content carries business details, customer, lines and grouped GHS totals', function () {
    $customer = Customer::factory()->create(['name' => 'Akwaaba Basic School', 'district' => 'Kumasi Metro', 'credit_limit' => null]);
    $product = stockedProduct(stock: 1000, price: 123450, attributes: ['title' => 'English Reader P4']);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 10]], $customer));

    $html = view('pdf.invoice', [
        'sale' => $sale,
        'business' => [
            'name' => 'Kumasi Book Depot',
            'address' => "12 Adum Road\nKumasi",
            'phone' => '0244000000',
            'footer' => 'MoMo 0244000000. Thank you.',
        ],
    ])->render();

    expect($html)
        ->toContain('Kumasi Book Depot')
        ->toContain('12 Adum Road<br />')
        ->toContain('INV-2026-000001')
        ->toContain('Akwaaba Basic School')
        ->toContain('Kumasi Metro, Greater Accra')
        ->toContain('English Reader P4')
        ->toContain('GHS 1,234.50')
        ->toContain('GHS 12,345.00')
        ->toContain('Balance due')
        ->toContain('01 Oct 2026')
        ->toContain('31 Oct 2026')
        ->toContain('MoMo 0244000000. Thank you.');
});

test('invoice is not available for drafts, cancelled or void sales', function (string $status) {
    $sale = Sale::factory()->create(['status' => $status, 'created_by' => $this->owner->id]);

    $this->withToken($this->token)->getJson("/api/v1/sales/{$sale->id}/invoice")
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_not_editable')
        ->assertJsonPath('details.action', 'invoice');
})->with(['draft', 'cancelled', 'void']);

// --- Authorization -------------------------------------------------------------------------------

dataset('sale action endpoints', [
    'confirm' => ['POST', 'confirm'],
    'cancel' => ['POST', 'cancel'],
    'void' => ['POST', 'void'],
    'deliver' => ['POST', 'deliver'],
    'invoice' => ['GET', 'invoice'],
]);

test('non-owner gets 403 on sale action endpoints', function (string $method, string $action) {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]]);
    $school = User::factory()->school()->create();

    $this->withToken($school->createToken('t')->plainTextToken)
        ->withHeader('Idempotency-Key', 'k')
        ->json($method, "/api/v1/sales/{$sale->id}/{$action}", ['reason' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect($sale->fresh()->status->value)->toBe('draft');
})->with('sale action endpoints');

test('unauthenticated requests get 401 on sale action endpoints', function (string $method, string $action) {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withHeader('Idempotency-Key', 'k')
        ->json($method, "/api/v1/sales/{$sale->id}/{$action}", ['reason' => 'x'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    expect($sale->fresh()->status->value)->toBe('draft');
})->with('sale action endpoints');
