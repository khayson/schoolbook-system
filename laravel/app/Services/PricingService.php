<?php

namespace App\Services;

use App\DTOs\Pricing\PricedLine;
use App\DTOs\Pricing\PricedOrder;
use App\DTOs\Pricing\PriceLineInput;
use App\Models\Customer;
use App\Models\Product;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class PricingService
{
    /**
     * Final interface (Phase 4 fills in rules). Phase 2: base selling_price only.
     *
     * @param  list<PriceLineInput|array{product_id: int, quantity: int, override_unit_price?: int|null, override_reason?: string|null}>  $lines
     */
    public function priceLines(?Customer $customer, array $lines, CarbonInterface $date): PricedOrder
    {
        if ($lines === []) {
            throw new InvalidArgumentException('At least one pricing line is required.');
        }

        $normalized = array_map(fn ($line): PriceLineInput => $this->normalizeLine($line), $lines);
        $productIds = array_values(array_unique(array_map(fn (PriceLineInput $line): int => $line->productId, $normalized)));
        sort($productIds);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $pricedLines = [];
        $subtotal = 0;
        $warnings = [];

        foreach ($normalized as $line) {
            $product = $products->get($line->productId);
            if ($product === null) {
                throw new InvalidArgumentException("Product [{$line->productId}] was not found.");
            }

            if ($line->quantity <= 0) {
                throw new InvalidArgumentException('Line quantity must be greater than zero.');
            }

            $basePrice = (int) $product->selling_price;
            $unitPrice = $basePrice;
            $isOverridden = false;
            $overrideReason = null;
            $lineWarnings = [];

            if ($line->overrideUnitPrice !== null) {
                if ($line->overrideReason === null || trim($line->overrideReason) === '') {
                    throw new InvalidArgumentException('Override reason is required when overriding unit price.');
                }
                $unitPrice = $line->overrideUnitPrice;
                $isOverridden = true;
                $overrideReason = trim($line->overrideReason);
            }

            if ($unitPrice < 0) {
                throw new InvalidArgumentException('Unit price cannot be negative.');
            }

            $discountPerUnit = max(0, $basePrice - $unitPrice);
            $discountAmount = $discountPerUnit * $line->quantity;
            $lineTotal = $unitPrice * $line->quantity;
            $subtotal += $lineTotal;

            $pricedLines[] = new PricedLine(
                productId: $product->id,
                productTitle: $product->title,
                quantity: $line->quantity,
                basePrice: $basePrice,
                unitPrice: $unitPrice,
                discountPerUnit: $discountPerUnit,
                discountAmount: $discountAmount,
                taxAmount: 0,
                lineTotal: $lineTotal,
                unitCost: (int) $product->cost_price,
                appliedRules: [],
                warnings: $lineWarnings,
                isPriceOverridden: $isOverridden,
                overrideReason: $overrideReason,
            );
        }

        return new PricedOrder(
            lines: $pricedLines,
            subtotal: $subtotal,
            discountTotal: 0,
            taxTotal: 0,
            total: $subtotal,
            warnings: $warnings,
        );
    }

    /**
     * @param  PriceLineInput|array{product_id: int, quantity: int, override_unit_price?: int|null, override_reason?: string|null}  $line
     */
    private function normalizeLine(PriceLineInput|array $line): PriceLineInput
    {
        if ($line instanceof PriceLineInput) {
            return $line;
        }

        return new PriceLineInput(
            productId: (int) $line['product_id'],
            quantity: (int) $line['quantity'],
            overrideUnitPrice: isset($line['override_unit_price']) ? (int) $line['override_unit_price'] : null,
            overrideReason: $line['override_reason'] ?? null,
        );
    }
}
