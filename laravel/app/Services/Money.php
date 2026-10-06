<?php

namespace App\Services;

use InvalidArgumentException;

class Money
{
    /**
     * Largest single money amount accepted from input: GHS 1,000,000,000.00. Far above any
     * real school order or payment, far below the 64-bit column limit, so absurd input is a
     * 422 instead of a database error.
     */
    public const MAX_PESEWAS = 100_000_000_000;

    /**
     * Convert a GHS amount to integer pesewas without floating-point math.
     *
     * Accepts whole numbers or strings with at most 2 decimal places
     * (e.g. "19.99", "0.29", "1.10"). Floats are rejected.
     */
    public static function ghsToPesewas(string|int|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (is_int($amount)) {
            if ($amount < 0) {
                throw new InvalidArgumentException('GHS amount cannot be negative.');
            }

            return $amount * 100;
        }

        $normalized = trim(str_replace(',', '', $amount));

        if ($normalized === '') {
            return 0;
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException(
                'GHS amount must be a non-negative number with at most 2 decimal places.',
            );
        }

        if (str_contains($normalized, '.')) {
            [$whole, $fraction] = explode('.', $normalized, 2);
            $fraction = str_pad($fraction, 2, '0');
        } else {
            $whole = $normalized;
            $fraction = '00';
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    public static function pesewasToGhs(?int $pesewas): string
    {
        if ($pesewas === null) {
            return '0.00';
        }

        $sign = $pesewas < 0 ? '-' : '';
        $absolute = abs($pesewas);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        return sprintf('%s%d.%02d', $sign, $whole, $fraction);
    }

    /**
     * Sign of a displayed negative amount, before the currency: "−GHS 196.00" (U+2212
     * minus sign). The same style as the app's Money.formatPesewas.
     */
    public const NEGATIVE_SIGN = '−';

    /** Display without separators: "GHS 1250.50", "−GHS 1250.50". */
    public static function formatGhs(?int $pesewas): string
    {
        $pesewas ??= 0;

        return ($pesewas < 0 ? self::NEGATIVE_SIGN : '').'GHS '.self::pesewasToGhs(abs($pesewas));
    }

    /**
     * Display (invoices, receipts, reports): thousands separators, e.g. "GHS 12,345.60",
     * "−GHS 196.00". Integer arithmetic throughout; number_format only sees whole cedis.
     */
    public static function formatGhsGrouped(?int $pesewas): string
    {
        $pesewas ??= 0;
        $sign = $pesewas < 0 ? self::NEGATIVE_SIGN : '';
        $absolute = abs($pesewas);

        return sprintf('%sGHS %s.%02d', $sign, number_format(intdiv($absolute, 100)), $absolute % 100);
    }
}
