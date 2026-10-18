<?php

namespace App\Enums;

enum PriceRuleType: string
{
    case PercentOff = 'percent_off';
    case AmountOff = 'amount_off';
    case FixedPrice = 'fixed_price';
    case OrderPercentOff = 'order_percent_off';
    case OrderAmountOff = 'order_amount_off';
    // Stretch — reserved; PricingService ignores until implemented.
    case BonusUnits = 'bonus_units';

    public function isOrderLevel(): bool
    {
        return $this === self::OrderPercentOff || $this === self::OrderAmountOff;
    }

    public function isLineLevel(): bool
    {
        return ! $this->isOrderLevel() && $this !== self::BonusUnits;
    }
}
