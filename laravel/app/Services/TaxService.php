<?php

namespace App\Services;

use App\Models\TaxRate;

/**
 * Tax on a tax-exclusive subtotal. Each active rate is applied independently
 * (not compounded); amounts are half-up to the nearest pesewa.
 */
class TaxService
{
    /**
     * @return array{
     *     lines: list<array{code: string, name: string, rate_bps: int, amount: int}>,
     *     tax_total: int
     * }
     */
    public function taxOnSubtotal(int $taxExclusiveSubtotal): array
    {
        $base = max(0, $taxExclusiveSubtotal);
        $lines = [];
        $taxTotal = 0;

        foreach (TaxRate::query()->active()->get() as $rate) {
            $amount = $this->halfUpBps($base, (int) $rate->rate_bps);
            $lines[] = [
                'code' => $rate->code,
                'name' => $rate->name,
                'rate_bps' => (int) $rate->rate_bps,
                'amount' => $amount,
            ];
            $taxTotal += $amount;
        }

        return [
            'lines' => $lines,
            'tax_total' => $taxTotal,
        ];
    }

    /** Half-up: amount * bps / 10000. */
    public function halfUpBps(int $amount, int $basisPoints): int
    {
        $bps = min(10000, max(0, $basisPoints));

        return (int) intdiv(($amount * $bps) + 5000, 10000);
    }
}
