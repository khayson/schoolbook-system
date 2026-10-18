<?php

namespace App\Actions\Inventory\Concerns;

use App\Enums\StockExchangeDirection;
use App\Exceptions\InvalidInputException;
use App\Models\Product;

trait NormalizesStockExchangeItems
{
    /**
     * @param  list<array{direction: string, product_id: int, quantity: int, unit_cost?: int|null, unit_price?: int|null}>  $items
     * @return list<array{direction: StockExchangeDirection, product_id: int, quantity: int, unit_cost: int|null, unit_price: int|null}>
     */
    protected function normalizeExchangeItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidInputException('items', 'Add at least one book line (who took it, which book, and how many).');
        }

        /** @var array<string, array{direction: StockExchangeDirection, product_id: int, quantity: int, unit_cost: int|null, unit_price: int|null}> $merged */
        $merged = [];

        foreach ($items as $index => $item) {
            $direction = StockExchangeDirection::tryFrom((string) ($item['direction'] ?? ''));
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitCost = array_key_exists('unit_cost', $item) && $item['unit_cost'] !== null
                ? (int) $item['unit_cost']
                : null;
            $unitPrice = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                ? (int) $item['unit_price']
                : null;

            if ($direction === null) {
                throw new InvalidInputException(
                    "items.{$index}.direction",
                    'Say whether they gave us the book (Taken) or they took it from us.',
                );
            }

            if ($productId <= 0 || ! Product::query()->whereKey($productId)->where('is_active', true)->exists()) {
                throw new InvalidInputException("items.{$index}.product_id", 'Each line needs a valid active book.');
            }

            if ($quantity <= 0) {
                throw new InvalidInputException(
                    "items.{$index}.quantity",
                    'Enter how many copies of this book they took or gave (must be at least 1).',
                );
            }

            if ($unitCost !== null && $unitCost < 0) {
                throw new InvalidInputException("items.{$index}.unit_cost", 'Unit cost cannot be negative.');
            }

            if ($unitPrice !== null && $unitPrice < 0) {
                throw new InvalidInputException("items.{$index}.unit_price", 'Unit price cannot be negative.');
            }

            if ($direction === StockExchangeDirection::Out) {
                $unitCost = null;
            } else {
                $unitPrice = null;
            }

            $key = $direction->value.'|'.$productId;

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'direction' => $direction,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'unit_price' => $unitPrice,
                ];

                continue;
            }

            if ($merged[$key]['unit_cost'] !== $unitCost || $merged[$key]['unit_price'] !== $unitPrice) {
                throw new InvalidInputException(
                    "items.{$index}.product_id",
                    'The same book cannot appear twice on the same side with different prices. Merge the quantities instead.',
                );
            }

            $merged[$key]['quantity'] += $quantity;
        }

        $lines = array_values($merged);
        usort($lines, function (array $a, array $b): int {
            return [$a['direction']->value, $a['product_id']] <=> [$b['direction']->value, $b['product_id']];
        });

        return $lines;
    }
}
