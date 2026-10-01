<?php

namespace App\Actions\Sales;

use App\Enums\PaymentStatus;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateDraftSale
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     sale_date?: string|\DateTimeInterface,
     *     due_date?: string|\DateTimeInterface|null,
     *     notes?: string|null,
     *     source?: string,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         override_unit_price?: int|null,
     *         override_reason?: string|null
     *     }>
     * }  $data
     */
    public function execute(User $user, array $data): Sale
    {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new InvalidArgumentException('A draft sale needs at least one line item.');
        }

        return DB::transaction(function () use ($user, $data, $items) {
            $customer = Customer::query()
                ->whereKey($data['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $customer->is_active) {
                throw new InvalidArgumentException('Cannot create a sale for an inactive customer.');
            }

            $saleDate = Carbon::parse($data['sale_date'] ?? now()->toDateString());
            $priced = $this->pricing->priceLines($customer, $items, $saleDate);

            $sale = Sale::query()->create([
                'invoice_no' => null,
                'customer_id' => $customer->id,
                'status' => SaleStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid,
                'source' => SaleSource::tryFrom((string) ($data['source'] ?? 'staff')) ?? SaleSource::Staff,
                'sale_date' => $saleDate,
                'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date']) : null,
                'subtotal' => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total' => $priced->taxTotal,
                'total' => $priced->total,
                'amount_paid' => 0,
                'balance_due' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($priced->lines as $line) {
                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $line->productId,
                    'product_title' => $line->productTitle,
                    'quantity' => $line->quantity,
                    'base_price' => $line->basePrice,
                    'unit_price' => $line->unitPrice,
                    'discount_amount' => $line->discountAmount,
                    'tax_amount' => $line->taxAmount,
                    'line_total' => $line->lineTotal,
                    'unit_cost' => $line->unitCost,
                    'applied_rules' => array_map(
                        fn ($rule) => $rule->toArray(),
                        $line->appliedRules,
                    ),
                    'is_price_overridden' => $line->isPriceOverridden,
                    'override_reason' => $line->overrideReason,
                ]);
            }

            return $sale->load(['customer', 'items.product', 'createdBy']);
        });
    }
}
