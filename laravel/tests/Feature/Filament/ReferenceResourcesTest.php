<?php

use App\Actions\Reference\PublishReferenceEdition;
use App\Filament\Resources\ReferenceBooks\Pages\ListReferenceBooks;
use App\Filament\Resources\ReferenceBooks\ReferenceBookResource;
use App\Filament\Resources\ReferenceEditions\Pages\ListReferenceEditions;
use App\Filament\Resources\ReferenceEditions\Pages\ReviewReferenceEdition;
use App\Filament\Resources\ReferenceEditions\ReferenceEditionResource;
use App\Models\Language;
use App\Models\Level;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function reviewPage(ReferenceEdition $edition)
{
    return Livewire::test(ReviewReferenceEdition::class, ['record' => $edition->getRouteKey()]);
}

function filamentRow(ReferenceEdition $edition, string $title, ?string $serial = null): ReferenceImportRow
{
    return stagedRow($edition, $title, $serial);
}

test('the review page lists rows needing attention first', function () {
    $edition = stageList($this->owner);

    $rows = $edition->importRows()->get()->keyBy(fn ($r) => $r->title.'#'.$r->source_serial);
    reviewPage($edition)
        ->assertOk()
        ->assertSee('Review: Test list')
        ->assertSee('12 rows read (12 new, 0 unchanged, 0 changed)')
        ->assertCanSeeTableRecords([
            $rows['Discover Science#2'],               // error
            $rows['Science Around Us#3'],              // warnings
            $rows['Twi Kasa Workbook 1#2'],
            $rows['Asante Twi Reader for Primary 3#3'],
            $rows['Sunrise Mathematics for Basic Schools#1'], // clean, undecided
        ], inOrder: true)
        ->filterTable('decision', 'error')
        ->assertCanSeeTableRecords([$rows['Discover Science#2']])
        ->assertCountTableRecords(1)
        ->filterTable('decision', 'warning')
        ->assertCountTableRecords(3)
        ->assertCanNotSeeTableRecords([$rows['Fun with Fractions for Primary 2#4']]);
});

test('rows are accepted, excluded and fixed from the review page', function () {
    $edition = stageList($this->owner);
    $clean = filamentRow($edition, 'Sunrise Mathematics for Basic Schools', '1');
    $duplicate = filamentRow($edition, 'Discover Science', '2');

    reviewPage($edition)
        ->assertActionHidden(TestAction::make('accept')->table($duplicate))
        ->callAction(TestAction::make('accept')->table($clean))
        ->callAction(TestAction::make('exclude')->table($duplicate));

    expect($clean->fresh()->resolved)->toBeTrue()
        ->and($duplicate->fresh()->excluded)->toBeTrue();

    $twi = filamentRow($edition, 'Twi Kasa Workbook 1');
    reviewPage($edition)
        ->callAction(TestAction::make('fix')->table($twi), data: [
            'language_id' => Language::query()->where('code', 'tw-ak')->value('id'),
            'level_id' => Level::query()->where('slug', 'primary-1')->value('id'),
        ])
        ->assertHasNoActionErrors();

    expect($twi->fresh())
        ->resolved->toBeTrue()
        ->issues->toBeNull()
        ->level_label->toBe('Primary 1')
        ->confidence->toBe('high');
});

test('a publisher suggestion is applied to every row spelled the same', function () {
    $edition = stageList($this->owner);
    $row = filamentRow($edition, 'Asante Twi Reader for Primary 3');

    reviewPage($edition)
        ->assertActionVisible(TestAction::make('useSuggestion')->table($row))
        ->callAction(TestAction::make('useSuggestion')->table($row));

    expect($row->fresh()->publisher_label)->toBe('Lakeside Publications and Stationery Ltd');
});

test('accept in bulk, then publish with a confirmation that shows the counts', function () {
    $edition = stageList($this->owner);

    reviewPage($edition)
        ->callAction('acceptAllNew')
        ->assertNotified('8 rows accepted');

    reviewPage($edition)
        ->mountAction('publish')
        ->assertMountedActionModalSee('Accepted rows go live: 8 new titles, 0 changed, 0 confirmed unchanged, 0 withdrawn.')
        ->assertMountedActionModalSee('4 undecided and 0 excluded rows are left out.')
        ->callMountedAction()
        ->assertRedirect(ReferenceEditionResource::getUrl('index'));

    expect($edition->fresh()->status)->toBe('active')
        ->and(ReferenceBook::query()->count())->toBe(8);

    // Published: read-only from now on.
    reviewPage($edition->fresh())
        ->assertActionHidden('publish')
        ->assertActionHidden('discard')
        ->assertActionHidden(TestAction::make('accept')->table(filamentRow($edition, 'Twi Kasa Workbook 1')));
});

test('the imports list links drafts to review and can discard them', function () {
    $published = stageList($this->owner);
    reviewEverything($published);
    app(PublishReferenceEdition::class)->execute($this->owner, $published);
    $draft = stageList($this->owner, secondEditionFixture(), 'sha-2', 'Second list');

    Livewire::test(ListReferenceEditions::class)
        ->assertCanSeeTableRecords([$draft, $published])
        ->assertActionVisible(TestAction::make('review')->table($draft))
        ->assertActionHidden(TestAction::make('review')->table($published))
        ->assertActionHidden(TestAction::make('discard')->table($published))
        ->callAction(TestAction::make('discard')->table($draft));

    expect($draft->fresh()->status)->toBe('discarded');
});

test('the approved titles list is read-only and filters to approved by default', function () {
    $edition = stageList($this->owner);
    reviewEverything($edition);
    app(PublishReferenceEdition::class)->execute($this->owner, $edition);
    ReferenceBook::query()->where('title', 'The Clever Tortoise')->update(['status' => 'withdrawn']);

    Livewire::test(ListReferenceBooks::class)
        ->assertOk()
        ->assertSee('Live list: Test list')
        ->assertCanSeeTableRecords(ReferenceBook::query()->where('status', 'approved')->get())
        ->assertCanNotSeeTableRecords(ReferenceBook::query()->where('status', 'withdrawn')->get())
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->searchTable('lakeside')
        ->assertCountTableRecords(3);
});

test('only the owner reaches the approved-list pages', function () {
    $edition = stageList($this->owner);
    $this->actingAs(User::factory()->create(['role' => 'school']));

    $this->get(ReferenceEditionResource::getUrl('index'))->assertForbidden();
    $this->get(ReferenceEditionResource::getUrl('review', ['record' => $edition]))->assertForbidden();
    $this->get(ReferenceBookResource::getUrl('index'))->assertForbidden();
});

test('changes and the page help read as plain text, with no escaped markup', function () {
    $first = stageList($this->owner);
    reviewEverything($first);
    app(PublishReferenceEdition::class)->execute($this->owner, $first);
    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');

    reviewPage($second)
        ->assertSee('Changed: publisher label "Sunrise Press Limited" → "Sunrise Press Company Ltd"')
        ->assertDontSee('["Sunrise', escape: false);

    Livewire::test(ListReferenceEditions::class)
        ->assertSee('Nothing goes live until you publish it.')
        ->assertDontSee('&lt;', escape: false);
});
