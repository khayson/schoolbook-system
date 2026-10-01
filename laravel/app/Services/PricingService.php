<?php

namespace App\Services;

use App\DTOs\Pricing\PricedLine;
use App\DTOs\Pricing\PricedOrder;
use App\DTOs\Pricing\PriceLineInput;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\Product;
use Carbon\CarbonInterface;

class PricingService
{
    /**
     * Final interface (Phase 4 fills in rules). Phase 2: base selling_price only.
     *
     * Duplicate lines with the same (product, override price, override reason) are
     * merged into one line with summed quantity, keeping first-appearance order.
     *
     * @param  list<PriceLineInput|array{product_id: int, quantity: int, override_unit_price?: int|null, override_reason?: string|null}>  $lines
     */
    public function priceLines(?Customer $customer, array $lines, CarbonInterface $date): PricedOrder
    {
        if ($lines === []) {
            throw new InvalidInputException('items', 'At least one line item is required.');
        }

        $merged = $this->mergeLines(array_values($lines));
        $productIds = array_values(array_unique(array_map(fn (array $entry): int => $entry['line']->productId, $merged)));
        sort($productIds);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $pricedLines = [];
        $subtotal = 0;
        $warnings = [];

        foreach ($merged as ['line' => $line, 'index' => $index]) {
            $product = $products->get($line->productId);
            if ($product === null) {
                throw new InvalidInputException("items.{$index}.product_id", "Product [{$line->productId}] was not found.");
            }

            if (! $product->is_active) {
                throw new InvalidInputException("items.{$index}.product_id", "Product [{$product->sku}] is inactive.");
            }

            $basePrice = (int) $product->selling_price;
            $unitPrice = $basePrice;
            $isOverridden = false;
            $overrideReason = null;
            $lineWarnings = [];

            if ($line->overrideUnitPrice !== null) {
                $unitPrice = $line->overrideUnitPrice;
                $isOverridden = true;
                $overrideReason = $line->overrideReason;
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
     * Validate each input line, then merge duplicates by (product, override price, reason).
     *
     * @param  list<PriceLineInput|array<string, mixed>>  $lines
     * @return list<array{line: PriceLineInput, index: int}> index = first input position, for error keys
     */
    private function mergeLines(array $lines): array
    {
        /** @var array<string, array{line: PriceLineInput, index: int}> $merged */
        $merged = [];

        foreach ($lines as $index => $raw) {
            $line = $this->normalizeLine($raw, $index);
            $key = implode('|', [$line->productId, $line->overrideUnitPrice ?? '', $line->overrideReason ?? '']);

            if (! isset($merged[$key])) {
                $merged[$key] = ['line' => $line, 'index' => $index];

                continue;
            }

            $existing = $merged[$key]['line'];
            $merged[$key]['line'] = new PriceLineInput(
                productId: $existing->productId,
                quantity: $existing->quantity + $line->quantity,
                overrideUnitPrice: $existing->overrideUnitPrice,
                overrideReason: $existing->overrideReason,
            );
        }

        return array_values($merged);
    }

    /**
     * @param  PriceLineInput|array<string, mixed>  $line
     */
    private function normalizeLine(PriceLineInput|array $line, int $index): PriceLineInput
    {
        if (is_array($line)) {
            if (! isset($line['product_id']) || ! is_numeric($line['product_id'])) {
                throw new InvalidInputException("items.{$index}.product_id", 'Each line needs a valid product_id.');
            }

            $line = new PriceLineInput(
                productId: (int) $line['product_id'],
                quantity: (int) ($line['quantity'] ?? 0),
                overrideUnitPrice: isset($line['override_unit_price']) ? (int) $line['override_unit_price'] : null,
                overrideReason: isset($line['override_reason']) ? (string) $line['override_reason'] : null,
            );
        }

        if ($line->quantity <= 0) {
            throw new InvalidInputException("items.{$index}.quantity", 'Line quantity must be greater than zero.');
        }

        if ($line->overrideUnitPrice === null) {
            // A reason without an override price carries no meaning; drop it so it cannot split a merge.
            return $line->overrideReason === null ? $line : new PriceLineInput($line->productId, $line->quantity);
        }

        if ($line->overrideUnitPrice < 0) {
            throw new InvalidInputException("items.{$index}.override_unit_price", 'Unit price cannot be negative.');
        }

        $reason = trim((string) $line->overrideReason);
        if ($reason === '') {
            throw new InvalidInputException("items.{$index}.override_reason", 'Override reason is required when overriding unit price.');
        }

        return new PriceLineInput($line->productId, $line->quantity, $line->overrideUnitPrice, $reason);
    }
}
