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
