<?php

namespace App\Services\Reference;

use App\Models\ReferenceBook;

/**
 * Compares a staged row with the live title it matches (same natural key).
 * The serial number is not compared: it changes whenever the list is renumbered.
 */
class ReferenceDiff
{
    public const COMPARED = ['title', 'level_label', 'band', 'subject_id', 'language_id', 'author', 'publisher_label'];

    /**
     * @param  array<string, mixed>  $row
     * @return array{action: string, changes: ?array<string, array{0: mixed, 1: mixed}>, reference_book_id: ?int}
     */
    public static function compare(array $row, ?ReferenceBook $book): array
    {
        if ($book === null) {
            return ['action' => 'new', 'changes' => null, 'reference_book_id' => null];
        }

        $changes = [];
        foreach (self::COMPARED as $field) {
            if ((string) $book->{$field} !== (string) ($row[$field] ?? '')) {
                $changes[$field] = [$book->{$field}, $row[$field] ?? null];
            }
        }
        if ($book->status !== 'approved') {
            $changes['status'] = [$book->status, 'approved'];
        }

        return [
            'action' => $changes === [] ? 'unchanged' : 'changed',
            'changes' => $changes === [] ? null : $changes,
            'reference_book_id' => $book->id,
        ];
    }
}
