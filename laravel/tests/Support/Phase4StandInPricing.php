<?php

namespace Tests\Support;

use App\DTOs\Pricing\PricedOrder;
use App\Models\Customer;
use App\Services\PricingService;
use Carbon\CarbonInterface;

/**
 * Stand-in for a Phase 4 order-level rule, so reports can be tested against a real
 * discount_total today: 5% off an order of 15 books or more. Everything else is the
 * Phase 2 PricingService, and the real CreateDraftSale/ConfirmSale store the result.
 */
final class Phase4StandInPricing extends PricingService
{
    public function priceLines(?Customer $customer, array $lines, CarbonInterface $date): PricedOrder
    {
        $order = parent::priceLines($customer, $lines, $date);
        $books = array_sum($order->quantitiesByProduct());
        if ($books < 15) {
            return $order;
        }
        $discount = intdiv($order->subtotal * 5, 100);

        return new PricedOrder(
            lines: $order->lines,
            subtotal: $order->subtotal,
            discountTotal: $discount,
            taxTotal: 0,
            total: $order->subtotal - $discount,
            warnings: $order->warnings,
        );
    }
}
