<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\WritesSaleItems;
use App\Enums\SaleStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleNotEditableException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateDraftSale
{
    use WritesSaleItems;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly CauserResolver $causer,
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
            throw new SaleNotEditableException($sale);
        }

        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $sale, $data) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Draft) {
                throw new SaleNotEditableException($locked);
            }

            $customer = $this->activeCustomer((int) ($data['customer_id'] ?? $locked->customer_id));

            $saleDate = Carbon::parse($data['sale_date'] ?? $locked->sale_date);
            $items = $data['items'] ?? $locked->items->map(fn (SaleItem $item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'override_unit_price' => $item->is_price_overridden ? $item->unit_price : null,
                'override_reason' => $item->override_reason,
            ])->all();

            if ($items === []) {
                throw new InvalidInputException('items', 'A draft sale needs at least one line item.');
            }

            $priced = $this->pricing->priceLines($customer, $items, $saleDate);

            $existingOverrideKeys = $locked->items
                ->filter(fn (SaleItem $item): bool => $item->is_price_overridden)
                ->map(fn (SaleItem $item): string => self::overrideKey($item))
                ->values()
                ->all();

            $locked->items()->delete();
            $this->writeItems($locked, $priced, $user, $existingOverrideKeys);

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
        }));
    }
}
