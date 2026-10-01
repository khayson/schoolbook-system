<?php

use App\Services\Money;

test('ghsToPesewas converts exact decimal strings without floats', function () {
    expect(Money::ghsToPesewas('19.99'))->toBe(1999)
        ->and(Money::ghsToPesewas('0.29'))->toBe(29)
        ->and(Money::ghsToPesewas('1.10'))->toBe(110)
        ->and(Money::ghsToPesewas('0.01'))->toBe(1)
        ->and(Money::ghsToPesewas('12'))->toBe(1200)
        ->and(Money::ghsToPesewas('1,234.56'))->toBe(123456)
        ->and(Money::ghsToPesewas(5))->toBe(500)
        ->and(Money::ghsToPesewas(null))->toBe(0)
        ->and(Money::ghsToPesewas(''))->toBe(0);
});

test('ghsToPesewas rejects more than two decimal places', function () {
    expect(fn () => Money::ghsToPesewas('1.234'))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn () => Money::ghsToPesewas('0.001'))
        ->toThrow(\InvalidArgumentException::class);
});

test('pesewasToGhs formats without float drift', function () {
    expect(Money::pesewasToGhs(1999))->toBe('19.99')
        ->and(Money::pesewasToGhs(29))->toBe('0.29')
        ->and(Money::pesewasToGhs(110))->toBe('1.10')
        ->and(Money::formatGhs(1999))->toBe('GHS 19.99');
});
