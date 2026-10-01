<?php

namespace App\Actions\Sales\Concerns;

use App\DTOs\Pricing\AppliedRuleSummary;
use App\DTOs\Pricing\PricedLine;
use App\DTOs\Pricing\PricedOrder;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;

trait WritesSaleItems
{
    /**
     * Plain read, no lock: drafts must not queue behind payments for the same customer.
     * The customer row is locked only in ConfirmSale and payment actions.
     */
    private function activeCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->find($customerId);

        if ($customer === null || ! $customer->is_active) {
            throw new InvalidInputException('customer_id', 'The selected customer is inactive or does not exist.');
        }

        return $customer;
    }

    /**
     * Write priced lines as sale_items and log every price override (spec 7.5).
     *
     * @param  list<string>  $existingOverrideKeys  overrides already on the sale; not logged again
     */
    private function writeItems(Sale $sale, PricedOrder $priced, User $user, array $existingOverrideKeys = []): void
    {
        foreach ($priced->lines as $line) {
            $item = SaleItem::query()->create([
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
                    fn (AppliedRuleSummary $rule): array => $rule->toArray(),
                    $line->appliedRules,
                ),
                'is_price_overridden' => $line->isPriceOverridden,
                'override_reason' => $line->overrideReason,
            ]);

            if ($line->isPriceOverridden && ! in_array(self::overrideKey($line), $existingOverrideKeys, true)) {
                activity()
                    ->performedOn($sale)
                    ->causedBy($user)
                    ->event('price_overridden')
                    ->withProperties([
                        'sale_item_id' => $item->id,
                        'product_id' => $line->productId,
                        'product_title' => $line->productTitle,
                        'quantity' => $line->quantity,
                        'base_price' => $line->basePrice,
                        'unit_price' => $line->unitPrice,
                        'reason' => $line->overrideReason,
                    ])
                    ->log('price_overridden');
            }
        }
    }

    private static function overrideKey(PricedLine|SaleItem $line): string
    {
        return $line instanceof SaleItem
            ? implode('|', [$line->product_id, $line->unit_price, $line->override_reason])
            : implode('|', [$line->productId, $line->unitPrice, $line->overrideReason]);
    }
}
