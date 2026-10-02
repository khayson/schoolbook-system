<?php

namespace App\Filament\Support;

use App\Services\Money;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * A GHS amount typed as text and converted to integer pesewas exactly.
 *
 * Deliberately not ->numeric(): a numeric input can arrive as a float, and 0.29 * 100 is
 * 28.999... The string goes straight to Money::ghsToPesewas (decimal-string parsing).
 * State inside the form is the GHS string; the dehydrated value is pesewas (int|null).
 */
final class GhsInput
{
    public const PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('GHS')
            ->inputMode('decimal')
            ->placeholder('0.00')
            ->rule('regex:'.self::PATTERN)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $pesewas = self::toPesewas($value);
                if ($pesewas !== null && $pesewas > Money::MAX_PESEWAS) {
                    $fail('The amount is larger than the system accepts.');
                }
            })
            ->validationMessages(['regex' => 'Enter an amount like 1250 or 1250.50 (digits, at most 2 decimals).'])
            ->formatStateUsing(fn (mixed $state): ?string => is_int($state) ? Money::pesewasToGhs($state) : $state)
            ->dehydrateStateUsing(fn (mixed $state): ?int => self::toPesewas($state));
    }

    /**
     * Form state (GHS string) to pesewas; null for blank or unparseable input.
     */
    public static function toPesewas(mixed $state): ?int
    {
        if ($state === null || (is_string($state) && trim($state) === '')) {
            return null;
        }

        try {
            return Money::ghsToPesewas(is_int($state) ? $state : trim((string) $state));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
