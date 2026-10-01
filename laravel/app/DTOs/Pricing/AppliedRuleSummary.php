<?php

namespace App\DTOs\Pricing;

readonly class AppliedRuleSummary
{
    /**
     * @param  array<string, mixed>  $effect
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $effect,
    ) {}

    /**
     * @return array{id: int, name: string, effect: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'effect' => $this->effect,
        ];
    }
}
