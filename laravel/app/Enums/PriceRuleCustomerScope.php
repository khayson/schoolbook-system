<?php

namespace App\Enums;

enum PriceRuleCustomerScope: string
{
    case All = 'all';
    case Customer = 'customer';
    case CustomerType = 'customer_type';
    case Region = 'region';

    /** Higher = more specific (customer > customer_type > region > all). */
    public function specificity(): int
    {
        return match ($this) {
            self::Customer => 40,
            self::CustomerType => 30,
            self::Region => 20,
            self::All => 10,
        };
    }
}
