<?php

namespace App\Actions\Reference;

use App\Exceptions\ReferenceImportException;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Services\Reference\ReferenceDiff;
use App\Services\Reference\ReferenceMapper;
use Illuminate\Support\Facades\DB;

/**
 * The owner's decisions on a draft edition's rows: accept, exclude, fix, merge a publisher
 * spelling. Only accepted (resolved, not excluded) rows are applied on publish.
 */
class ReviewReferenceImport
{
    /** Fields the review page may correct. */
    public const EDITABLE = ['category', 'title', 'level_id', 'band', 'subject_id', 'language_id', 'author', 'publisher_label'];

    public function accept(ReferenceImportRow $row): ReferenceImportRow
    {
        $this->assertDraft($row->edition);
        if ($row->hasErrors()) {
            throw ReferenceImportException::rowHasErrors();
        }
        $row->forceFill(['resolved' => true, 'excluded' => false])->save();

        return $row;
    }

    public function exclude(ReferenceImportRow $row): ReferenceImportRow
    {
        $this->assertDraft($row->edition);
        $row->forceFill(['resolved' => true, 'excluded' => true])->save();

        return $row;
    }

    public function include(ReferenceImportRow $row): ReferenceImportRow
    {
        $this->assertDraft($row->edition);
        $row->forceFill(['resolved' => false, 'excluded' => false])->save();

        return $row;
    }

    /**
     * Accepts every clean (issue-free, not excluded) row with the given diff action.
     */
    public function acceptAll(ReferenceEdition $edition, string $action): int
    {
        $this->assertDraft($edition);

        return $edition->importRows()
            ->where('action', $action)
            ->where('excluded', false)
            ->where('resolved', false)
            ->whereNull('issues')
            ->update(['resolved' => true, 'updated_at' => now()]);
    }

    /**
     * Corrects a row by hand. The corrected row is re-keyed and re-compared with the live
     * list; its issues are re-checked (keyword guesses no longer apply: a person chose).
     * The row counts as reviewed unless an error remains.
     *
     * @param  array<string, mixed>  $data  subset of EDITABLE
     */
    public function update(ReferenceImportRow $row, array $data): ReferenceImportRow
    {
        $this->assertDraft($row->edition);
        if ($row->action === 'removed') {
            throw ReferenceImportException::removedRowNotEditable();
        }

        return DB::transaction(function () use ($row, $data): ReferenceImportRow {
            $attributes = array_intersect_key($data, array_flip(self::EDITABLE));
            foreach (['author', 'publisher_label', 'title'] as $text) {
                if (array_key_exists($text, $attributes) && $attributes[$text] !== null) {
                    $attributes[$text] = ReferenceMapper::squish((string) $attributes[$text]);
                }
            }
            $row->forceFill($attributes);

            if (array_key_exists('level_id', $attributes)) {
                $row->level_label = $row->level_id === null
                    ? $row->level_label
                    : (string) DB::table('levels')->where('id', $row->level_id)->value('name');
            }
            if (array_key_exists('subject_id', $attributes) && $row->subject_id !== null) {
                $row->subject_label = null;
            }
            if (array_key_exists('publisher_label', $attributes)) {
                $row->publisher_id = (new ReferenceMapper)->publisherIdFor((string) $row->publisher_label);
            }

            $this->rekey($row);
            $row->confidence = 'high';
            $row->issues = $this->recheck($row) ?: null;
            $row->resolved = ! $row->hasErrors();
            $row->excluded = false;
            $row->save();

            return $row;
        });
    }

    /**
     * "Same publisher?" → yes: every row of this draft spelled like this one takes the
     * suggested spelling (and publisher, when it is an existing one). The old spelling
     * becomes an alias on publish.
     */
    public function applyPublisherSuggestion(ReferenceImportRow $row): int
    {
        $this->assertDraft($row->edition);
        $issue = $row->issuesWithCode('publisher_similar')[0] ?? null;
        if ($issue === null) {
            return 0;
        }
        $normalized = ReferenceMapper::normalizePublisher($row->publisher_label);

        return DB::transaction(function () use ($row, $issue, $normalized): int {
            $count = 0;
            $siblings = $row->edition->importRows()->where('action', '!=', 'removed')->whereNull('publisher_id')->get();
            foreach ($siblings as $sibling) {
                if (ReferenceMapper::normalizePublisher($sibling->publisher_label) !== $normalized) {
                    continue;
                }
                $sibling->publisher_label = $issue['data']['suggestion'];
                $sibling->publisher_id = $issue['data']['publisher_id'] ?? null;
                $sibling->issues = array_values(array_filter(
                    $sibling->issues ?? [],
                    fn (array $i) => $i['code'] !== 'publisher_similar',
                )) ?: null;
                $this->rekey($sibling);
                $sibling->save();
                $count++;
            }

            return $count;
        });
    }

    private function rekey(ReferenceImportRow $row): void
    {
        $row->natural_key = ReferenceMapper::naturalKey(
            $row->category,
            ReferenceMapper::searchTitle($row->title),
            $row->level_id,
            $row->band,
            $row->level_label,
            $row->publisher_id,
            $row->publisher_label,
        );
        $book = ReferenceBook::query()->where('natural_key', $row->natural_key)->first();
        $row->forceFill(ReferenceDiff::compare($row->only(ReferenceDiff::COMPARED), $book));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recheck(ReferenceImportRow $row): array
    {
        $issues = [];
        if (trim($row->publisher_label) === '') {
            $issues[] = ReferenceMapper::issue('missing_publisher');
        }
        if ($row->category === 'textbook' && $row->level_id === null) {
            $issues[] = ReferenceMapper::issue('unknown_level', ['level' => $row->level_label]);
        }
        if ($row->subject_id === null && $row->issuesWithCode('subject_missing') !== []) {
            $issues = [...$issues, ...$row->issuesWithCode('subject_missing')];
        }
        if ($row->reference_book_id !== null) {
            $other = ReferenceBook::query()->where('natural_key', $row->natural_key)->whereKeyNot($row->reference_book_id)->first(['id', 'title']);
            if ($other !== null) {
                $issues[] = ReferenceMapper::issue('key_collision', ['book_id' => $other->id, 'title' => $other->title]);
            }
        }
        $duplicate = ReferenceImportRow::query()
            ->where('reference_edition_id', $row->reference_edition_id)
            ->where('id', '!=', $row->id)
            ->where('natural_key', $row->natural_key)
            ->where('excluded', false)
            ->where('action', '!=', 'removed')
            ->value('source_serial');
        if ($duplicate !== null) {
            $issues[] = ReferenceMapper::issue('duplicate', ['serial' => $duplicate]);
        }

        return $issues;
    }

    private function assertDraft(ReferenceEdition $edition): void
    {
        if (! $edition->isDraft()) {
            throw ReferenceImportException::notDraft();
        }
    }
}
