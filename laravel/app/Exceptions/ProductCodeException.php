<?php

namespace App\Exceptions;

use App\Models\Product;

class ProductCodeException extends ApiDomainException
{
    public static function duplicate(string $code, Product $owner, string $field): self
    {
        return new self(
            "Code {$code} already belongs to {$owner->title} ({$owner->sku}).",
            'duplicate_code',
            409,
            [
                'code' => $code,
                'field' => $field,
                'product_id' => $owner->id,
                'sku' => $owner->sku,
                'title' => $owner->title,
                'deleted' => $owner->trashed(),
            ],
        );
    }

    public static function slotTaken(string $code, string $field, string $current): self
    {
        return new self(
            "This product already has a different {$field} ({$current}). Change it on the product first if {$code} is the right one.",
            'code_slot_taken',
            409,
            ['code' => $code, 'field' => $field, 'current' => $current],
        );
    }
}
