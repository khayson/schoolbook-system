<?php

namespace App\Services\Reference;

use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;

/**
 * The whole live approved list for the phone to keep and search offline. Reference data
 * only (titles, levels, subjects, publishers): stock comes from the products API, so a
 * sale does not invalidate the snapshot.
 */
class ReferenceSnapshot
{
    /** @var array{payload: array, json: string, etag: string}|null built once per request */
    private ?array $built = null;

    /**
     * Hash of the exact payload: it changes when, and only when, what the phone would
     * download changes (not on sales, not on unrelated publishers or products).
     */
    public function etag(): string
    {
        return $this->build()['etag'];
    }

    public function json(): string
    {
        return $this->build()['json'];
    }

    /**
     * @return array{payload: array, json: string, etag: string}
     */
    private function build(): array
    {
        if ($this->built === null) {
            $payload = $this->payload();
            $json = json_encode(['data' => $payload], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->built = ['payload' => $payload, 'json' => $json, 'etag' => '"'.sha1($json).'"'];
        }

        return $this->built;
    }

    /**
     * @return array{edition: ?array, count: int, books: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        $edition = ReferenceEdition::active();
        $books = ReferenceBook::query()->approved()
            ->with(['level:id,name', 'subject:id,name', 'language:id,name', 'publisher:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (ReferenceBook $b): array => [
                'id' => $b->id,
                'category' => $b->category,
                'title' => $b->title,
                'search_title' => $b->search_title,
                'level_id' => $b->level_id,
                'level' => $b->level?->name ?? $b->level_label,
                'band' => $b->band,
                'subject_id' => $b->subject_id,
                'subject' => $b->subject?->name ?? $b->subject_label,
                'language_id' => $b->language_id,
                'language' => $b->language?->name,
                'publisher_id' => $b->publisher_id,
                'publisher' => $b->publisher?->name ?? $b->publisher_label,
                'author' => $b->author,
                'isbn' => $b->isbn,
            ])
            ->all();

        return [
            'edition' => $edition === null ? null : [
                'id' => $edition->id,
                'label' => $edition->label,
                'published_at' => $edition->published_at?->toDateString(),
            ],
            'count' => count($books),
            'books' => $books,
        ];
    }
}
