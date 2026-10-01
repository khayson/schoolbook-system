<?php

namespace App\DTOs\Pricing;

readonly class PriceLineInput
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public ?int $overrideUnitPrice = null,
        public ?string $overrideReason = null,
    ) {}
}
