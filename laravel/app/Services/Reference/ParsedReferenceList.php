<?php

namespace App\Services\Reference;

/**
 * Parser output. statedCounts holds the statistics tables printed in the list itself
 * (section 2), so an import can be compared with what NaCCA says it contains.
 */
final class ParsedReferenceList
{
    /**
     * @param  list<ParsedReferenceRow>  $rows
     * @param  list<array{page: int, serial: string, section: ?string, reason: string, raw: string}>  $skipped
     * @param  array{textbook: array<string, int>, supplementary: array<string, int>}  $statedCounts
     */
    public function __construct(
        public array $rows,
        public array $skipped,
        public array $statedCounts,
    ) {}
}
