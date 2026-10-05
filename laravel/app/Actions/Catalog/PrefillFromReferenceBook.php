<?php

namespace App\Actions\Catalog;

use App\Models\ReferenceBook;

/**
 * Fills a new product from its approved-list title. Explicit values always win: only
 * fields that are missing or blank are filled. The title gets the variant in brackets
 * ("Learner's Book", "Teacher's Guide") so two products of one title stay distinguishable.
 *
 * A title listed only for a level band (supplementary materials: "Lower Primary") has no
 * single level; the caller must then choose one.
 */
class PrefillFromReferenceBook
{
    public const FIELDS = ['title', 'level_id', 'subject_id', 'language_id', 'publisher_id'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function apply(array $data): array
    {
        $bookId = $data['reference_book_id'] ?? null;
        if (! is_numeric($bookId)) {
            return $data;
        }
        $book = ReferenceBook::query()->find((int) $bookId);
        if ($book === null) {
            return $data;
        }

        $variant = trim((string) ($data['variant_label'] ?? ''));
        $defaults = [
            'title' => $variant === '' ? $book->title : "{$book->title} ({$variant})",
            'level_id' => $book->level_id,
            'subject_id' => $book->subject_id,
            'language_id' => $book->language_id,
            'publisher_id' => $book->publisher_id,
        ];

        foreach ($defaults as $field => $value) {
            $given = $data[$field] ?? null;
            if (($given === null || (is_string($given) && trim($given) === '')) && $value !== null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }
}
