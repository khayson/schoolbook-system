<?php

namespace App\Actions\Catalog;

use App\Exceptions\ProductCodeException;
use App\Models\Product;
use App\Models\ReferenceBook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Scan to learn": a scanned code no product knows is attached to one product.
 *
 * - A valid ISBN-13 (978/979, check digit) goes to products.isbn; anything else to
 *   products.barcode. Numeric codes ignore spaces and hyphens ("978-9988-0-1234-5").
 * - A code another product already has (as SKU, ISBN or barcode, including soft-deleted
 *   products, which still hold it) is refused: 409 duplicate_code.
 * - Attaching the code the product already has is a no-op; a different code in an
 *   occupied slot is refused (409 code_slot_taken) rather than overwritten.
 * - When the product is linked to an approved title without an ISBN, the title learns it.
 */
class AttachProductCode
{
    public function execute(Product $product, string $code): Product
    {
        $code = self::normalize($code);
        $field = self::isIsbn13($code) ? 'isbn' : 'barcode';

        try {
            return DB::transaction(function () use ($product, $code, $field): Product {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);

                if (in_array($code, [$product->sku, $product->isbn, $product->barcode], true)) {
                    return $product->load(['level', 'subject', 'language', 'publisher']);
                }

                $owner = Product::withTrashed()
                    ->whereKeyNot($product->id)
                    ->where(fn ($q) => $q->where('sku', $code)->orWhere('isbn', $code)->orWhere('barcode', $code))
                    ->first();
                if ($owner !== null) {
                    $ownerField = match ($code) {
                        $owner->isbn => 'isbn',
                        $owner->barcode => 'barcode',
                        default => 'sku',
                    };

                    throw ProductCodeException::duplicate($code, $owner, $ownerField);
                }

                $current = $product->{$field};
                if ($current !== null && $current !== '') {
                    throw ProductCodeException::slotTaken($code, $field, $current);
                }

                $product->{$field} = $code;
                $product->save();

                if ($field === 'isbn' && $product->reference_book_id !== null) {
                    ReferenceBook::query()->whereKey($product->reference_book_id)->whereNull('isbn')->update(['isbn' => $code]);
                }

                return $product->load(['level', 'subject', 'language', 'publisher']);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request attached the same code a moment ago.
            $owner = Product::withTrashed()->where(fn ($q) => $q->where('isbn', $code)->orWhere('barcode', $code))->firstOrFail();

            throw ProductCodeException::duplicate($code, $owner, $owner->isbn === $code ? 'isbn' : 'barcode');
        }
    }

    /**
     * Numeric codes (EAN/ISBN) lose spaces and hyphens: "978-9988-0-1234-5" is the
     * barcode 9789988012345. Other codes (a SKU such as BK-000123) are kept as typed.
     */
    public static function normalize(string $code): string
    {
        $code = trim($code);
        $digits = (string) preg_replace('/[\s\-]+/', '', $code);

        return ctype_digit($digits) ? $digits : $code;
    }

    public static function isIsbn13(string $code): bool
    {
        if (! preg_match('/^97[89]\d{10}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }
}
