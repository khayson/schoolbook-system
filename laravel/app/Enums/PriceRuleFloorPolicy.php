<?php

namespace App\Enums;

enum PriceRuleFloorPolicy: string
{
    case BlockBelowCost = 'block_below_cost';
    case Warn = 'warn';
    case Allow = 'allow';

    /** Strictest wins among applied rules. */
    public function severity(): int
    {
        return match ($this) {
            self::BlockBelowCost => 30,
            self::Warn => 20,
            self::Allow => 10,
        };
    }

    public static function strictest(self ...$policies): self
    {
        $best = self::Allow;
        foreach ($policies as $policy) {
            if ($policy->severity() > $best->severity()) {
                $best = $policy;
            }
        }

        return $best;
    }
}
