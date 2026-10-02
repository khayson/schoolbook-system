<?php

namespace App\DTOs\Ledger;

readonly class InvariantViolation
{
    public function __construct(
        public string $invariant,
        public string $subjectType,
        public int $subjectId,
        public ?int $customerId,
        public string $field,
        public int|string|null $expected,
        public int|string|null $actual,
    ) {}

    /**
     * @return array{invariant: string, subject: string, customer_id: int|null, field: string, expected: int|string|null, actual: int|string|null}
     */
    public function toArray(): array
    {
        return [
            'invariant' => $this->invariant,
            'subject' => "{$this->subjectType}#{$this->subjectId}",
            'customer_id' => $this->customerId,
            'field' => $this->field,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            '%s: %s#%d %s expected %s, actual %s',
            $this->invariant,
            $this->subjectType,
            $this->subjectId,
            $this->field,
            var_export($this->expected, true),
            var_export($this->actual, true),
        );
    }
}
