<?php

namespace App\Enums;

enum PriceRuleScopeType: string
{
    case All = 'all';
    case Product = 'product';
    case Publisher = 'publisher';
    case Subject = 'subject';
    case Language = 'language';
    case Level = 'level';
    case LevelGroup = 'level_group';

    /** Higher = more specific (product > publisher > subject = language > level > level_group > all). */
    public function specificity(): int
    {
        return match ($this) {
            self::Product => 60,
            self::Publisher => 50,
            self::Subject, self::Language => 40,
            self::Level => 30,
            self::LevelGroup => 20,
            self::All => 10,
        };
    }
}
