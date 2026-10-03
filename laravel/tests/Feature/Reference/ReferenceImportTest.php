<?php

use App\Actions\Reference\DiscardReferenceEdition;
use App\Actions\Reference\PublishReferenceEdition;
use App\Actions\Reference\ReviewReferenceImport;
use App\Actions\Sales\ConfirmSale;
use App\Exceptions\ReferenceImportException;
use App\Models\Language;
use App\Models\Level;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReferenceListFixture;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

test('staging maps levels, bands, subjects, languages and authors, and flags what needs a look', function () {
    $edition = stageList($this->owner);
    $level = fn (string $slug) => Level::query()->where('slug', $slug)->value('id');
    $subject = fn (string $slug) => Subject::query()->where('slug', $slug)->value('id');
    $language = fn (string $code) => Language::query()->where('code', $code)->value('id');

    $basic1 = stagedRow($edition, 'Sunrise Mathematics for Basic Schools', '1');
    expect($basic1->only(['category', 'level_label', 'level_id', 'band', 'subject_id', 'language_id', 'author', 'publisher_label', 'confidence', 'issues', 'action']))
        ->toBe([
            'category' => 'textbook', 'level_label' => 'Basic 1', 'level_id' => $level('primary-1'), 'band' => null,
            'subject_id' => $subject('mathematics'), 'language_id' => $language('en'), 'author' => null,
            'publisher_label' => 'Sunrise Press Ltd', 'confidence' => 'high', 'issues' => null, 'action' => 'new',
        ]);

    // "JHS1" spelled without a space.
    expect(stagedRow($edition, 'Lakeside Series Mathematics for Junior High Schools'))
        ->level_label->toBe('JHS 1')
        ->level_id->toBe($level('jhs-1'));

    // Supplementary: author split off, level band from the heading, level and subject guessed.
    $games = stagedRow($edition, 'Number Games Activity Book for Basic 2');
    expect($games->only(['level_label', 'band', 'level_id', 'subject_id', 'author', 'publisher_label', 'confidence']))->toBe([
        'level_label' => 'Lower Primary', 'band' => 'lower_primary', 'level_id' => $level('primary-2'),
        'subject_id' => $subject('mathematics'), 'author' => 'Ama Owusu & Kofi Asare', 'publisher_label' => 'Sunrise Press Ltd', 'confidence' => 'low',
    ]);

    $asante = stagedRow($edition, 'Asante Twi Reader for Primary 3');
    expect($asante->language_id)->toBe($language('tw-as'))
        ->and($asante->subject_id)->toBe($subject('ghanaian-language'))
        ->and($asante->level_id)->toBe($level('primary-3'))
        ->and(issueCodes($asante))->toBe(['publisher_similar'])
        ->and($asante->issues[0]['data'])->toBe(['suggestion' => 'Lakeside Publications and Stationery Ltd']);

    // "Twi" alone: Asante or Akuapem? Left for the owner.
    $twi = stagedRow($edition, 'Twi Kasa Workbook 1');
    expect($twi->language_id)->toBeNull()->and(issueCodes($twi))->toBe(['language_unknown']);

    expect(issueCodes(stagedRow($edition, 'Discover Science', '1')))->toBe([])
        ->and(issueCodes(stagedRow($edition, 'Discover Science', '2')))->toBe(['duplicate'])
        ->and(stagedRow($edition, 'Discover Science', '2')->hasErrors())->toBeTrue()
        ->and(issueCodes(stagedRow($edition, 'Science Around Us')))->toBe(['continued_on_next_page']);

    $reader = stagedRow($edition, 'The Clever Tortoise');
    expect($reader->only(['category', 'level_label', 'level_id', 'band', 'subject_id', 'confidence']))->toBe([
        'category' => 'reader', 'level_label' => null, 'level_id' => null, 'band' => null,
        'subject_id' => $subject('english-language'), 'confidence' => 'low',
    ]);

    // Spelling variants of one publisher share a key part: "Ltd" = "Limited".
    $edition->refresh();
    expect($edition->only(['status', 'rows_total', 'rows_new', 'rows_unchanged', 'rows_changed', 'rows_removed', 'rows_with_issues', 'rows_skipped']))->toBe([
        'status' => 'draft', 'rows_total' => 12, 'rows_new' => 12, 'rows_unchanged' => 0, 'rows_changed' => 0,
        'rows_removed' => 0, 'rows_with_issues' => 4, 'rows_skipped' => 1,
    ])
        ->and($edition->stated_counts['textbook'])->toBe(['Numeracy and Mathematics' => 5, 'Science' => 3]);
});

test('nothing goes live while a list is staged', function () {
    $publishers = Publisher::query()->count();
    stageList($this->owner);

    expect(ReferenceBook::query()->count())->toBe(0)
        ->and(Publisher::query()->count())->toBe($publishers)
        ->and(PublisherAlias::query()->count())->toBe(0)
        ->and(ReferenceImportRow::query()->where('resolved', true)->count())->toBe(0);
});

test('only one list can wait for review, and the live file cannot be staged again', function () {
    $draft = stageList($this->owner);
    expect(fn () => stageList($this->owner, sha: 'sha-2'))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_draft_exists'));

    reviewEverything($draft);
    app(PublishReferenceEdition::class)->execute($this->owner, $draft);

    expect(fn () => stageList($this->owner, sha: 'sha-1'))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_already_imported'));
    expect(fn () => stageList($this->owner, new ReferenceListFixture, 'sha-3'))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_nothing_parsed'));
});

test('publishing applies only the accepted rows and leaves the rest out', function () {
    $edition = stageList($this->owner);
    $review = app(ReviewReferenceImport::class);
    $review->accept(stagedRow($edition, 'Sunrise Mathematics for Basic Schools', '1'));
    $review->accept(stagedRow($edition, 'Discover Science', '1'));
    $review->exclude(stagedRow($edition, 'Discover Science', '2'));
    $review->accept(stagedRow($edition, 'The Clever Tortoise'));

    $summary = app(PublishReferenceEdition::class)->execute($this->owner, $edition);

    expect($summary)->toMatchArray(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'pending' => 8, 'excluded' => 1])
        ->and(ReferenceBook::query()->orderBy('id')->pluck('title')->all())->toBe(['Sunrise Mathematics for Basic Schools', 'Discover Science', 'The Clever Tortoise'])
        ->and(ReferenceBook::query()->where('status', 'approved')->count())->toBe(3)
        ->and($edition->fresh()->status)->toBe('active');

    $book = ReferenceBook::query()->where('title', 'Discover Science')->firstOrFail();
    expect($book->first_seen_edition_id)->toBe($edition->id)
        ->and($book->last_seen_edition_id)->toBe($edition->id)
        ->and($book->search_title)->toBe('discover science')
        ->and($book->publisher->name)->toBe('Baobab Publishing');
});

test('accepting a row with an error is refused until it is fixed or excluded', function () {
    $edition = stageList($this->owner);
    $duplicate = stagedRow($edition, 'Discover Science', '2');
    $review = app(ReviewReferenceImport::class);

    expect(fn () => $review->accept($duplicate))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_row_has_errors'));

    // Fixing it into a different title (here: the next level) clears the duplicate.
    $fixed = $review->update($duplicate, ['level_id' => Level::query()->where('slug', 'primary-5')->value('id')]);
    expect($fixed->hasErrors())->toBeFalse()
        ->and($fixed->resolved)->toBeTrue()
        ->and($fixed->level_label)->toBe('Primary 5')
        ->and($fixed->confidence)->toBe('high');

    // A fix that recreates the duplicate brings the error back.
    $again = $review->update($fixed, ['level_id' => Level::query()->where('slug', 'primary-4')->value('id')]);
    expect(issueCodes($again))->toBe(['duplicate'])->and($again->resolved)->toBeFalse();
});

test('one publisher per spelling group is created and every spelling becomes an alias', function () {
    $edition = stageList($this->owner);
    $review = app(ReviewReferenceImport::class);

    // "Same publisher?" yes: Stationeies -> Stationery.
    expect($review->applyPublisherSuggestion(stagedRow($edition, 'Asante Twi Reader for Primary 3')))->toBe(1);
    $merged = stagedRow($edition, 'Asante Twi Reader for Primary 3');
    expect($merged->publisher_label)->toBe('Lakeside Publications and Stationery Ltd')
        ->and($merged->printed_publisher)->toBe('Lakeside Publications and Stationeies Ltd')
        ->and(issueCodes($merged))->toBe([]);

    reviewEverything($edition);
    $summary = app(PublishReferenceEdition::class)->execute($this->owner, $edition);

    // Sunrise (Ltd/Limited, plus the author row), Lakeside (3 spellings), Harmattan, Baobab, Kente, Odwira, Akwaaba.
    expect($summary['publishers_created'])->toBe(7)
        ->and(Publisher::query()->where('name', 'like', 'Sunrise%')->count())->toBe(1)
        ->and(Publisher::query()->where('name', 'like', 'Lakeside%')->count())->toBe(1);

    $lakeside = Publisher::query()->where('name', 'like', 'Lakeside%')->firstOrFail();
    expect($lakeside->aliases()->pluck('alias')->sort()->values()->all())->toBe([
        'Lakeside Publications and Stationeies Ltd',
        'Lakeside Publications and Stationery Ltd',
    ])
        ->and(ReferenceBook::query()->where('publisher_id', $lakeside->id)->count())->toBe(3);

    // Next edition: the misspelling resolves on its own, with no question.
    $next = stageList($this->owner, (new ReferenceListFixture)->page()
        ->heading('4.0 – LIST OF APPROVED SUPPLEMENTARY MATERIALS', 166.3)
        ->heading('4.1 SUBJECT-BASED SUPPLEMENTARY MATERIALS')
        ->heading('Lower Primary')
        ->supplementHeader()
        ->supplement('1', 'Asante Twi Reader for Primary 3', 'Lakeside Publications and Stationeies Ltd'), 'sha-2');
    $row = stagedRow($next, 'Asante Twi Reader for Primary 3');
    expect($row->publisher_id)->toBe($lakeside->id)
        ->and(issueCodes($row))->toBe([])
        ->and($row->action)->toBe('changed') // printed spelling differs from the merged one
        ->and(array_keys($row->changes))->toBe(['publisher_label']);
});

test('a later edition is compared with the live list: unchanged, changed, new, removed', function () {
    $first = stageList($this->owner);
    reviewEverything($first);
    app(PublishReferenceEdition::class)->execute($this->owner, $first);

    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');
    $actions = $second->importRows()->orderBy('position')->get()
        ->mapWithKeys(fn (ReferenceImportRow $r) => [$r->title.' '.($r->level_label ?? '-') => $r->action])->all();

    expect($actions)->toBe([
        'Sunrise Mathematics for Basic Schools Basic 1' => 'unchanged',
        'Sunrise Mathematics for Basic Schools Basic 2' => 'changed',
        'Lakeside Series Mathematics for Junior High Schools JHS 1' => 'unchanged',
        'Discover Science Basic 4' => 'unchanged',
        'Ocean Science Basic 5' => 'new',
        'The Clever Tortoise -' => 'unchanged',
        // Live titles missing from the new list
        'Counting Fun for Kindergarten KG 2' => 'removed',
        'Science Around Us JHS 2' => 'removed',
        'Number Games Activity Book for Basic 2 Lower Primary' => 'removed',
        'Twi Kasa Workbook 1 Lower Primary' => 'removed',
        'Asante Twi Reader for Primary 3 Lower Primary' => 'removed',
        'Fun with Fractions for Primary 2 Lower Primary' => 'removed',
    ])
        ->and(stagedRow($second, 'Sunrise Mathematics for Basic Schools', '2')->changes)
        ->toBe(['publisher_label' => ['Sunrise Press Limited', 'Sunrise Press Company Ltd']])
        ->and($second->fresh()->only(['rows_total', 'rows_new', 'rows_unchanged', 'rows_changed', 'rows_removed']))
        ->toBe(['rows_total' => 6, 'rows_new' => 1, 'rows_unchanged' => 4, 'rows_changed' => 1, 'rows_removed' => 6]);
});

test('withdrawn titles stay, keep their products working, and come back if relisted', function () {
    $first = stageList($this->owner);
    reviewEverything($first);
    app(PublishReferenceEdition::class)->execute($this->owner, $first);

    $counting = ReferenceBook::query()->where('title', 'Counting Fun for Kindergarten')->firstOrFail();
    $product = stockedProduct(10, attributes: ['reference_book_id' => $counting->id, 'title' => 'Counting Fun KG 2']);

    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');
    $review = app(ReviewReferenceImport::class);
    expect($review->acceptAll($second, 'unchanged'))->toBe(4)
        ->and($review->acceptAll($second, 'removed'))->toBe(6)
        ->and($review->acceptAll($second, 'new'))->toBe(1)
        ->and($review->acceptAll($second, 'changed'))->toBe(1);
    $summary = app(PublishReferenceEdition::class)->execute($this->owner, $second);

    expect($summary)->toMatchArray(['created' => 1, 'updated' => 1, 'unchanged' => 4, 'withdrawn' => 6])
        ->and($counting->fresh()->status)->toBe('withdrawn')
        ->and($counting->fresh()->last_seen_edition_id)->toBe($first->id)
        ->and(ReferenceBook::query()->count())->toBe(12) // 11 from the first list (duplicate excluded) + Ocean Science
        ->and($first->fresh()->status)->toBe('superseded')
        ->and(ReferenceBook::query()->where('title', 'Discover Science')->value('last_seen_edition_id'))->toBe($second->id);

    // The product still sells.
    $sale = makeDraftSale($this->owner, [[$product->fresh(), 2]]);
    $confirmed = app(ConfirmSale::class)->execute($this->owner, $sale);
    expect($confirmed->status->value)->toBe('confirmed')
        ->and($product->fresh()->reference_book_id)->toBe($counting->id)
        ->and($product->fresh()->stock_on_hand)->toBe(8);

    // Relisted later: the same book is approved again (changed: status).
    $third = stageList($this->owner, (new ReferenceListFixture)->page()
        ->heading('3.0 LIST OF APPROVED TEXTBOOKS', 134.8)
        ->heading('NUMERACY/MATHEMATICS (LEARNER BOOKS)')
        ->textbookHeader()
        ->textbook('1', 'Counting Fun for Kindergarten', 'KG 2', 'Harmattan Books Ltd'), 'sha-3');
    $row = stagedRow($third, 'Counting Fun for Kindergarten');
    expect($row->action)->toBe('changed')
        ->and($row->changes)->toBe(['publisher_label' => ['Harmattan Books (GH) Ltd', 'Harmattan Books Ltd'], 'status' => ['withdrawn', 'approved']])
        ->and($row->reference_book_id)->toBe($counting->id);
    $review->accept($row);
    app(PublishReferenceEdition::class)->execute($this->owner, $third);
    expect($counting->fresh()->status)->toBe('approved');
});

test('a removed row can be kept on the list instead of withdrawn', function () {
    $first = stageList($this->owner);
    reviewEverything($first);
    app(PublishReferenceEdition::class)->execute($this->owner, $first);

    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');
    $review = app(ReviewReferenceImport::class);
    $review->exclude(stagedRow($second, 'Counting Fun for Kindergarten')); // "Keep on list"
    $review->acceptAll($second, 'removed');
    $review->acceptAll($second, 'unchanged');

    expect(fn () => $review->update(stagedRow($second, 'Science Around Us'), ['title' => 'x']))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_row_not_editable'));

    app(PublishReferenceEdition::class)->execute($this->owner, $second);

    expect(ReferenceBook::query()->where('title', 'Counting Fun for Kindergarten')->value('status'))->toBe('approved')
        ->and(ReferenceBook::query()->where('title', 'Science Around Us')->value('status'))->toBe('withdrawn');
});

test('a subject missing from the shop is created on publish, not before', function () {
    $edition = stageList($this->owner, (new ReferenceListFixture)->page()
        ->heading('3.0 LIST OF APPROVED TEXTBOOKS', 134.8)
        ->heading('PHYSICAL EDUCATION/PHYSICAL EDUCATION AND HEALTH (LEARNER BOOKS,TEACHER')
        ->heading('GUIDES)')
        ->textbookHeader()
        ->textbook('1', 'Move and Play', 'Basic 1', 'Harmattan Books Ltd'));
    $row = stagedRow($edition, 'Move and Play');
    expect(issueCodes($row))->toBe(['subject_missing'])
        ->and(Subject::query()->where('slug', 'physical-education-and-health')->exists())->toBeFalse();

    reviewEverything($edition);
    app(PublishReferenceEdition::class)->execute($this->owner, $edition);

    $subject = Subject::query()->where('slug', 'physical-education-and-health')->firstOrFail();
    expect($subject->name)->toBe('Physical Education and Health')
        ->and(ReferenceBook::query()->where('title', 'Move and Play')->value('subject_id'))->toBe($subject->id);
});

test('publishing needs at least one accepted row and a draft', function () {
    $edition = stageList($this->owner);
    expect(fn () => app(PublishReferenceEdition::class)->execute($this->owner, $edition))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_nothing_to_publish'));

    reviewEverything($edition);
    app(PublishReferenceEdition::class)->execute($this->owner, $edition);
    expect(fn () => app(PublishReferenceEdition::class)->execute($this->owner, $edition))
        ->toThrow(fn (ReferenceImportException $e) => expect($e->errorCode())->toBe('reference_not_draft'))
        ->and(fn () => app(ReviewReferenceImport::class)->accept($edition->importRows()->first()))
        ->toThrow(ReferenceImportException::class);
});

test('discarding a draft deletes its rows and leaves the live list alone', function () {
    $first = stageList($this->owner);
    reviewEverything($first);
    app(PublishReferenceEdition::class)->execute($this->owner, $first);

    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');
    app(DiscardReferenceEdition::class)->execute($this->owner, $second);

    expect($second->fresh()->status)->toBe('discarded')
        ->and($second->importRows()->count())->toBe(0)
        ->and(ReferenceBook::query()->where('status', 'approved')->count())->toBe(11)
        ->and(ReferenceEdition::active()->id)->toBe($first->id);

    // A new draft can now be staged.
    expect(stageList($this->owner, secondEditionFixture(), 'sha-2')->status)->toBe('draft');
});
