<?php

namespace App\Actions\Reference;

use App\Exceptions\ReferenceImportException;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\User;
use App\Services\Reference\ParsedReferenceList;
use App\Services\Reference\PdfChunkExtractor;
use App\Services\Reference\ReferenceDiff;
use App\Services\Reference\ReferenceListParser;
use App\Services\Reference\ReferenceMapper;
use Illuminate\Support\Facades\DB;

/**
 * Reads an approved list into a draft edition: every row is parsed, mapped and compared
 * with the live list (new / unchanged / changed / removed). Nothing live changes here;
 * PublishReferenceEdition applies the rows the owner has accepted.
 */
class StageReferenceImport
{
    /** Two publisher spellings at least this similar are offered as "same publisher?". */
    private const SIMILARITY = 0.85;

    public function __construct(
        private readonly PdfChunkExtractor $extractor,
        private readonly ReferenceListParser $parser,
    ) {}

    /**
     * @param  array{source_url?: ?string, published_at?: ?string}  $options
     */
    public function execute(User $user, string $path, string $label, array $options = []): ReferenceEdition
    {
        if (! is_file($path)) {
            throw ReferenceImportException::fileNotFound($path);
        }
        $sha = hash_file('sha256', $path);
        $this->guard($sha);

        return $this->stageParsed($user, $this->parser->parse($this->extractor->extract($path)), $label, $sha, $options);
    }

    /**
     * @param  array{source_url?: ?string, published_at?: ?string}  $options
     */
    public function stageParsed(User $user, ParsedReferenceList $list, string $label, string $sha, array $options = []): ReferenceEdition
    {
        $this->guard($sha);
        if ($list->rows === []) {
            throw ReferenceImportException::nothingParsed();
        }

        $mapper = new ReferenceMapper;
        $rows = array_map(fn ($row) => $mapper->map($row), $list->rows);
        $this->suggestPublishers($rows);

        foreach ($rows as &$row) {
            $row['natural_key'] = ReferenceMapper::naturalKey(
                $row['category'], $row['search_title'], $row['level_id'], $row['band'], $row['level_label'], $row['publisher_id'], $row['publisher_label'],
            );
        }
        unset($row);

        $this->flagDuplicates($rows);
        [$rows, $removed] = $this->diff($rows);

        return DB::transaction(function () use ($user, $list, $label, $sha, $options, $rows, $removed): ReferenceEdition {
            $this->guard($sha);

            $all = [...$rows, ...$removed];
            $count = fn (string $action) => count(array_filter($all, fn (array $r) => $r['action'] === $action));

            $edition = new ReferenceEdition;
            $edition->forceFill([
                'label' => $label,
                'source_url' => $options['source_url'] ?? null,
                'file_sha256' => $sha,
                'published_at' => $options['published_at'] ?? null,
                'imported_by' => $user->id,
                'status' => ReferenceEdition::STATUS_DRAFT,
                'rows_total' => count($rows),
                'rows_new' => $count('new'),
                'rows_unchanged' => $count('unchanged'),
                'rows_changed' => $count('changed'),
                'rows_removed' => $count('removed'),
                'rows_with_issues' => count(array_filter($rows, fn (array $r) => $r['issues'] !== [])),
                'rows_skipped' => count($list->skipped),
                'skipped' => $list->skipped,
                'stated_counts' => $list->statedCounts,
            ])->save();

            $now = now();
            foreach (array_chunk($all, 500) as $chunk) {
                DB::table('reference_import_rows')->insert(array_map(fn (array $r) => [
                    'reference_edition_id' => $edition->id,
                    'page' => $r['page'],
                    'position' => $r['position'],
                    'raw_text' => $r['raw_text'],
                    'category' => $r['category'],
                    'source_serial' => $r['source_serial'],
                    'title' => $r['title'],
                    'level_label' => $r['level_label'],
                    'level_id' => $r['level_id'],
                    'band' => $r['band'],
                    'subject_label' => $r['subject_label'],
                    'subject_id' => $r['subject_id'],
                    'language_id' => $r['language_id'],
                    'author' => $r['author'],
                    'publisher_label' => $r['publisher_label'],
                    'publisher_id' => $r['publisher_id'],
                    'confidence' => $r['confidence'],
                    'printed_publisher' => $r['action'] === 'removed' ? null : $r['publisher_label'],
                    'natural_key' => $r['natural_key'],
                    'issues' => $r['issues'] === [] ? null : json_encode($r['issues']),
                    'action' => $r['action'],
                    'changes' => $r['changes'] === null ? null : json_encode($r['changes']),
                    'reference_book_id' => $r['reference_book_id'],
                    'resolved' => false,
                    'excluded' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            activity('reference')->causedBy($user)->performedOn($edition)
                ->withProperties(['label' => $label, 'rows' => count($rows), 'removed' => count($removed)])
                ->log('reference list imported for review');

            return $edition;
        });
    }

    private function guard(string $sha): void
    {
        $draft = ReferenceEdition::query()->draft()->first();
        if ($draft !== null) {
            throw ReferenceImportException::draftExists($draft->id);
        }
        $active = ReferenceEdition::active();
        if ($active !== null && $active->file_sha256 === $sha) {
            throw ReferenceImportException::alreadyImported($active->id);
        }
    }

    /**
     * Unknown publisher spellings close to a known publisher, or to a more common spelling
     * in the same list, get a "same publisher?" suggestion. Only exact (normalized) matches
     * are merged automatically.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function suggestPublishers(array &$rows): void
    {
        $known = [];
        foreach (DB::table('publishers')->whereNull('deleted_at')->get(['id', 'name']) as $publisher) {
            $known[ReferenceMapper::normalizePublisher($publisher->name)] = ['label' => $publisher->name, 'id' => $publisher->id, 'count' => PHP_INT_MAX];
        }

        $spellings = [];
        foreach ($rows as $row) {
            if ($row['publisher_id'] === null && $row['publisher_label'] !== '') {
                $normalized = ReferenceMapper::normalizePublisher($row['publisher_label']);
                $spellings[$normalized]['count'] = ($spellings[$normalized]['count'] ?? 0) + 1;
                $spellings[$normalized]['label'] ??= $row['publisher_label'];
            }
        }

        $suggestions = [];
        foreach ($spellings as $normalized => $info) {
            $best = null;
            $bestScore = 0.0;
            // Union keeps the normalized names as keys (spread would renumber numeric ones).
            foreach ($known + array_map(fn ($s) => $s + ['id' => null], $spellings) as $candidate => $other) {
                $candidate = (string) $candidate;
                if ($candidate === $normalized || $candidate === '') {
                    continue;
                }
                // Point towards the established spelling: a known publisher, or a more common one.
                if ($other['count'] < $info['count'] || ($other['count'] === $info['count'] && $candidate > $normalized)) {
                    continue;
                }
                $score = 1 - levenshtein($normalized, $candidate) / max(strlen($normalized), strlen($candidate));
                if ($score >= self::SIMILARITY && $score > $bestScore) {
                    [$best, $bestScore] = [$other, $score];
                }
            }
            if ($best !== null) {
                $suggestions[$normalized] = ['suggestion' => $best['label'], 'publisher_id' => $best['id']];
            }
        }

        foreach ($rows as &$row) {
            if ($row['publisher_id'] !== null || $row['publisher_label'] === '') {
                continue;
            }
            $suggestion = $suggestions[ReferenceMapper::normalizePublisher($row['publisher_label'])] ?? null;
            if ($suggestion !== null) {
                $row['issues'][] = ReferenceMapper::issue('publisher_similar', array_filter($suggestion, fn ($v) => $v !== null));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flagDuplicates(array &$rows): void
    {
        $seen = [];
        foreach ($rows as &$row) {
            if (isset($seen[$row['natural_key']])) {
                $row['issues'][] = ReferenceMapper::issue('duplicate', ['serial' => $seen[$row['natural_key']]]);
            } else {
                $seen[$row['natural_key']] = $row['source_serial'];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function diff(array $rows): array
    {
        $books = ReferenceBook::query()->get()->keyBy('natural_key');
        $matched = [];

        foreach ($rows as &$row) {
            $book = $books->get($row['natural_key']);
            $row = [...$row, ...ReferenceDiff::compare($row, $book)];
            if ($book !== null) {
                $matched[$book->id] = true;
            }
        }
        unset($row);

        $removed = [];
        $position = count($rows);
        foreach ($books as $book) {
            if ($book->status !== 'approved' || isset($matched[$book->id])) {
                continue;
            }
            $removed[] = [
                'page' => null,
                'position' => ++$position,
                'raw_text' => null,
                'category' => $book->category,
                'source_serial' => $book->source_serial,
                'title' => $book->title,
                'level_label' => $book->level_label,
                'level_id' => $book->level_id,
                'band' => $book->band,
                'subject_label' => $book->subject_label,
                'subject_id' => $book->subject_id,
                'language_id' => $book->language_id,
                'author' => $book->author,
                'publisher_label' => $book->publisher_label,
                'publisher_id' => $book->publisher_id,
                'confidence' => $book->confidence,
                'natural_key' => $book->natural_key,
                'issues' => [],
                'action' => 'removed',
                'changes' => null,
                'reference_book_id' => $book->id,
            ];
        }

        return [$rows, $removed];
    }
}
