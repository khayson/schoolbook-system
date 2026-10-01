<?php

namespace App\DTOs\Pricing;

readonly class PricedOrder
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $lines,
        public int $subtotal,
        public int $discountTotal,
        public int $taxTotal,
        public int $total,
        public array $warnings = [],
    ) {}

    /**
     * Total quantity per product across all lines (a product can still appear on
     * several lines with different override prices). Use this for stock checks.
     *
     * @return array<int, int> product_id => quantity, sorted by product_id
     */
    public function quantitiesByProduct(): array
    {
        $totals = [];
        foreach ($this->lines as $line) {
            $totals[$line->productId] = ($totals[$line->productId] ?? 0) + $line->quantity;
        }
        ksort($totals);

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line): array => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'tax_total' => $this->taxTotal,
            'total' => $this->total,
            'warnings' => $this->warnings,
        ];
    }
}
