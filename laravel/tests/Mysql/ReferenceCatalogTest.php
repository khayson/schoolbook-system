<?php

use App\Actions\Reference\PublishReferenceEdition;
use App\Filament\Resources\ReferenceEditions\Pages\ReviewReferenceEdition;
use App\Models\ReferenceBook;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * MySQL stores JSON re-formatted ("severity": "error", with a space), and enforces the
 * foreign keys and enum columns; the review page's ordering and filters match on that text.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

test('staging, review ordering and filters, and publishing work on MySQL', function () {
    $edition = stageList($this->owner);
    $rows = $edition->importRows()->get()->keyBy(fn ($r) => $r->title.'#'.$r->source_serial);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ReviewReferenceEdition::class, ['record' => $edition->getRouteKey()])
        ->assertCanSeeTableRecords([
            $rows['Discover Science#2'],
            $rows['Science Around Us#3'],
            $rows['Sunrise Mathematics for Basic Schools#1'],
        ], inOrder: true)
        ->filterTable('decision', 'error')
        ->assertCountTableRecords(1)
        ->filterTable('decision', 'warning')
        ->assertCountTableRecords(3);

    reviewEverything($edition);
    $summary = app(PublishReferenceEdition::class)->execute($this->owner, $edition);

    // 8 publishers: the 'Stationeies' spelling was not merged here, so it stands alone.
    expect($summary)->toMatchArray(['created' => 11, 'excluded' => 1, 'pending' => 0, 'publishers_created' => 8])
        ->and(ReferenceBook::query()->count())->toBe(11);

    // Second edition diff on MySQL, then publish: withdrawals and updates.
    $second = stageList($this->owner, secondEditionFixture(), 'sha-2');
    expect($second->fresh()->only(['rows_new', 'rows_unchanged', 'rows_changed', 'rows_removed']))
        ->toBe(['rows_new' => 1, 'rows_unchanged' => 4, 'rows_changed' => 1, 'rows_removed' => 6]);
    reviewEverything($second);
    app(PublishReferenceEdition::class)->execute($this->owner, $second);

    expect(ReferenceBook::query()->where('status', 'withdrawn')->count())->toBe(6)
        ->and(ReferenceBook::query()->where('status', 'approved')->count())->toBe(6);
});
