<?php

namespace App\Services;

use App\Models\Product;

/**
 * SKUs for products created without one: BK-000001, BK-000002, ... from the "sku"
 * number sequence (no year). A number already taken by a hand-typed SKU is skipped.
 */
class ProductSkuGenerator
{
    public const PREFIX = 'BK';

    public function __construct(private readonly NumberSequenceService $sequences) {}

    public function next(): string
    {
        do {
            $sku = $this->sequences->format(self::PREFIX, 0, $this->sequences->next('sku'));
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
