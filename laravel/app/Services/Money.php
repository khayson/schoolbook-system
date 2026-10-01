<?php

namespace App\Services;

use InvalidArgumentException;

class Money
{
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

    public static function formatGhs(?int $pesewas): string
    {
        return 'GHS '.self::pesewasToGhs($pesewas);
    }

    /**
     * Display only (invoices, receipts): thousands separators, e.g. "GHS 12,345.60".
     * Integer arithmetic throughout; number_format only ever sees whole cedis.
     */
    public static function formatGhsGrouped(?int $pesewas): string
    {
        $pesewas ??= 0;
        $sign = $pesewas < 0 ? '-' : '';
        $absolute = abs($pesewas);

        return sprintf('GHS %s%s.%02d', $sign, number_format(intdiv($absolute, 100)), $absolute % 100);
    }
}
