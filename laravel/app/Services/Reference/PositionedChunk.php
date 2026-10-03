<?php

namespace App\Services\Reference;

/**
 * One run of text on a PDF page. Coordinates are PDF points: x grows to the right,
 * y grows upwards (so the top of an A4 page is y ≈ 842).
 */
final readonly class PositionedChunk
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public string $text,
    ) {}
}
