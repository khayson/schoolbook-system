<?php

namespace App\DTOs\Pricing;

readonly class PricedLine
{
    /**
     * @param  list<AppliedRuleSummary>  $appliedRules
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $productId,
        public string $productTitle,
        public int $quantity,
        public int $basePrice,
        public int $unitPrice,
        public int $discountPerUnit,
        public int $discountAmount,
        public int $taxAmount,
        public int $lineTotal,
        public int $unitCost,
        public array $appliedRules,
        public array $warnings,
        public bool $isPriceOverridden,
        public ?string $overrideReason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_title' => $this->productTitle,
            'quantity' => $this->quantity,
            'base_price' => $this->basePrice,
            'unit_price' => $this->unitPrice,
            'discount_per_unit' => $this->discountPerUnit,
            'discount_amount' => $this->discountAmount,
            'tax_amount' => $this->taxAmount,
            'line_total' => $this->lineTotal,
            'unit_cost' => $this->unitCost,
            'applied_rules' => array_map(
                fn (AppliedRuleSummary $rule): array => $rule->toArray(),
                $this->appliedRules,
            ),
            'warnings' => $this->warnings,
            'is_price_overridden' => $this->isPriceOverridden,
            'override_reason' => $this->overrideReason,
        ];
    }
}
