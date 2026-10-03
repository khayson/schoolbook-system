<?php

namespace App\Actions\Reference;

use App\Exceptions\ReferenceImportException;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Models\Subject;
use App\Models\User;
use App\Services\Reference\ReferenceMapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes a reviewed draft the live list. Only accepted rows (resolved, not excluded) are
 * applied: new titles are created, changed ones updated, unchanged ones marked as seen,
 * removed ones withdrawn (never deleted). Rows still pending are left out. Unknown
 * publishers are created once per spelling group and every spelling is kept as an alias.
 */
class PublishReferenceEdition
{
    /**
     * @return array{created: int, updated: int, unchanged: int, withdrawn: int, pending: int, excluded: int, publishers_created: int, merged: int}
     */
    public function execute(User $user, ReferenceEdition $edition): array
    {
        return DB::transaction(function () use ($user, $edition): array {
            $edition = ReferenceEdition::query()->lockForUpdate()->findOrFail($edition->id);
            if (! $edition->isDraft()) {
                throw ReferenceImportException::notDraft();
            }

            $accepted = $edition->importRows()->where('resolved', true)->where('excluded', false)->orderBy('position')->get();
            if ($accepted->isEmpty()) {
                throw ReferenceImportException::nothingToPublish();
            }
            if ($accepted->contains(fn (ReferenceImportRow $row) => $row->hasErrors())) {
                throw ReferenceImportException::rowHasErrors();
            }

            $live = $accepted->where('action', '!=', 'removed');
            $this->createMissingSubjects($live);
            $publishersCreated = $this->resolvePublishers($live);

            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'merged' => 0];
            $usedKeys = [];

            foreach ($accepted as $row) {
                if ($row->action === 'removed') {
                    ReferenceBook::query()->whereKey($row->reference_book_id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
                    $summary['withdrawn']++;

                    continue;
                }

                $key = ReferenceMapper::naturalKey(
                    $row->category, ReferenceMapper::searchTitle($row->title), $row->level_id, $row->band, $row->level_label, $row->publisher_id, $row->publisher_label,
                );
                if (isset($usedKeys[$key])) {
                    // Two spellings of one publisher resolved to the same title: one book.
                    $summary['merged']++;

                    continue;
                }
                $usedKeys[$key] = true;

                $book = $row->reference_book_id !== null
                    ? ReferenceBook::query()->find($row->reference_book_id)
                    : ReferenceBook::query()->where('natural_key', $key)->first();
                $isNew = $book === null;
                $book ??= (new ReferenceBook)->forceFill(['first_seen_edition_id' => $edition->id]);

                $book->forceFill([
                    'category' => $row->category,
                    'source_serial' => $row->source_serial,
                    'title' => $row->title,
                    'search_title' => ReferenceMapper::searchTitle($row->title),
                    'level_label' => $row->level_label,
                    'level_id' => $row->level_id,
                    'band' => $row->band,
                    'subject_label' => $row->subject_label,
                    'subject_id' => $row->subject_id,
                    'language_id' => $row->language_id,
                    'author' => $row->author,
                    'publisher_label' => $row->publisher_label,
                    'publisher_id' => $row->publisher_id,
                    'confidence' => $row->confidence,
                    'status' => 'approved',
                    'last_seen_edition_id' => $edition->id,
                    'natural_key' => $key,
                ])->save();

                $summary[match (true) {
                    $isNew => 'created',
                    $row->action === 'unchanged' => 'unchanged',
                    default => 'updated',
                }]++;
            }

            ReferenceEdition::query()->where('status', ReferenceEdition::STATUS_ACTIVE)
                ->update(['status' => ReferenceEdition::STATUS_SUPERSEDED, 'updated_at' => now()]);
            $edition->forceFill([
                'status' => ReferenceEdition::STATUS_ACTIVE,
                'activated_at' => now(),
                'activated_by' => $user->id,
            ])->save();

            $summary += [
                'pending' => $edition->importRows()->where('resolved', false)->count(),
                'excluded' => $edition->importRows()->where('excluded', true)->count(),
                'publishers_created' => $publishersCreated,
            ];

            activity('reference')->causedBy($user)->performedOn($edition)
                ->withProperties($summary)
                ->log('reference list published');

            return $summary;
        });
    }

    /**
     * @param  Collection<int, ReferenceImportRow>  $rows
     */
    private function createMissingSubjects(Collection $rows): void
    {
        foreach ($rows as $row) {
            $missing = $row->subject_id === null ? ($row->issuesWithCode('subject_missing')[0]['data'] ?? null) : null;
            if ($missing === null) {
                continue;
            }
            $subject = Subject::withTrashed()->firstOrCreate(['slug' => $missing['slug']], ['name' => $missing['name'], 'is_active' => true]);
            if ($subject->trashed()) {
                $subject->restore();
            }
            $row->subject_id = $subject->id;
            $row->subject_label = null;
            $row->save();
        }
    }

    /**
     * @param  Collection<int, ReferenceImportRow>  $rows
     */
    private function resolvePublishers(Collection $rows): int
    {
        $mapper = new ReferenceMapper;
        $created = 0;

        // Spellings not matching any publisher: one new publisher per normalized group,
        // named after the group's most common spelling.
        $groups = $rows->whereNull('publisher_id')
            ->groupBy(fn (ReferenceImportRow $row) => ReferenceMapper::normalizePublisher($row->publisher_label));
        foreach ($groups as $normalized => $group) {
            $publisherId = $mapper->publisherIdFor($group->first()->publisher_label);
            if ($publisherId === null) {
                $name = $group->countBy('publisher_label')->sortDesc()->keys()->first();
                $publisher = new Publisher;
                $publisher->forceFill(['name' => $name])->save();
                $publisherId = $publisher->id;
                $created++;
            }
            foreach ($group as $row) {
                $row->publisher_id = $publisherId;
                $row->save();
            }
        }

        // Every spelling seen, including the printed one a review merged away, resolves
        // automatically next time.
        $spellings = [];
        foreach ($rows as $row) {
            foreach (array_filter([$row->publisher_label, $row->printed_publisher]) as $spelling) {
                $spellings[ReferenceMapper::normalizePublisher($spelling)] ??= [$spelling, $row->publisher_id];
            }
        }
        foreach ($spellings as $normalized => [$spelling, $publisherId]) {
            if ($normalized === '' || PublisherAlias::query()->where('normalized_alias', $normalized)->exists()) {
                continue;
            }
            (new PublisherAlias)->forceFill([
                'alias' => $spelling,
                'normalized_alias' => $normalized,
                'publisher_id' => $publisherId,
            ])->save();
        }

        return $created;
    }
}
