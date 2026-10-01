<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\WritesSaleItems;
use App\Enums\PaymentStatus;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Exceptions\InvalidInputException;
use App\Models\Sale;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class CreateDraftSale
{
    use WritesSaleItems;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * Source is chosen by the calling code (staff API and Filament use the default;
     * the future school portal passes Portal). It is never taken from request input.
     *
     * @param  array{
     *     customer_id: int,
     *     sale_date?: string|\DateTimeInterface,
     *     due_date?: string|\DateTimeInterface|null,
     *     notes?: string|null,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         override_unit_price?: int|null,
     *         override_reason?: string|null
     *     }>
     * }  $data
     */
    public function execute(User $user, array $data, SaleSource $source = SaleSource::Staff): Sale
    {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new InvalidInputException('items', 'A draft sale needs at least one line item.');
        }

        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $data, $items, $source) {
            $customer = $this->activeCustomer((int) $data['customer_id']);

            $saleDate = Carbon::parse($data['sale_date'] ?? now()->toDateString());
            $priced = $this->pricing->priceLines($customer, $items, $saleDate);

            $sale = new Sale([
                'invoice_no' => null,
                'customer_id' => $customer->id,
                'status' => SaleStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid,
                'source' => $source,
                'sale_date' => $saleDate,
                'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date']) : null,
                'subtotal' => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total' => $priced->taxTotal,
                'total' => $priced->total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            // Cached money fields are not fillable; a draft owes nothing until confirmed.
            $sale->amount_paid = 0;
            $sale->balance_due = 0;
            $sale->save();

            $this->writeItems($sale, $priced, $user);

            return $sale->load(['customer', 'items.product', 'createdBy']);
        }));
    }
}
