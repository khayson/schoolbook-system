<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\PricingService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateDraftSale
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array{
     *     customer_id?: int,
     *     sale_date?: string|\DateTimeInterface,
     *     due_date?: string|\DateTimeInterface|null,
     *     notes?: string|null,
     *     items?: list<array{
     *         product_id: int,
     *         quantity: int,
     *         override_unit_price?: int|null,
     *         override_reason?: string|null
     *     }>
     * }  $data
     */
    public function execute(User $user, Sale $sale, array $data): Sale
    {
        if ($sale->status !== SaleStatus::Draft) {
            throw new DomainException('Only draft sales can be updated.');
        }

        return DB::transaction(function () use ($sale, $data) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Draft) {
                throw new DomainException('Only draft sales can be updated.');
            }

            $customerId = (int) ($data['customer_id'] ?? $locked->customer_id);
            $customer = Customer::query()
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $customer->is_active) {
                throw new InvalidArgumentException('Cannot assign an inactive customer to a sale.');
            }

            $saleDate = Carbon::parse($data['sale_date'] ?? $locked->sale_date);
            $items = $data['items'] ?? $locked->items->map(fn (SaleItem $item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'override_unit_price' => $item->is_price_overridden ? $item->unit_price : null,
                'override_reason' => $item->override_reason,
            ])->all();

            if ($items === []) {
                throw new InvalidArgumentException('A draft sale needs at least one line item.');
            }

            $priced = $this->pricing->priceLines($customer, $items, $saleDate);

            $locked->items()->delete();

            foreach ($priced->lines as $line) {
                SaleItem::query()->create([
                    'sale_id' => $locked->id,
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

            $locked->update([
                'customer_id' => $customer->id,
                'sale_date' => $saleDate,
                'due_date' => array_key_exists('due_date', $data)
                    ? ($data['due_date'] !== null ? Carbon::parse($data['due_date']) : null)
                    : $locked->due_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
                'subtotal' => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total' => $priced->taxTotal,
                'total' => $priced->total,
            ]);

            return $locked->fresh()->load(['customer', 'items.product', 'createdBy']);
        });
    }
}
