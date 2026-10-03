<?php

namespace App\Services\Reference;

/**
 * A row as printed in the list, before any mapping to levels, subjects or publishers.
 */
final class ParsedReferenceRow
{
    /**
     * @param  list<string>  $notes  parse-level warnings (codes), e.g. continued_on_next_page
     */
    public function __construct(
        public int $page,
        public int $position,
        public string $serial,
        public string $category,
        public ?string $section,
        public string $title,
        public ?string $level,
        public string $publisher,
        public string $raw,
        public array $notes = [],
    ) {}
}
