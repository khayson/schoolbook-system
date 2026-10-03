<?php

namespace App\Filament\Resources\ReferenceEditions\Pages;

use App\Actions\Reference\DiscardReferenceEdition;
use App\Actions\Reference\PublishReferenceEdition;
use App\Actions\Reference\ReviewReferenceImport;
use App\Filament\Resources\ReferenceEditions\ReferenceEditionResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\Language;
use App\Models\Level;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\ReferenceImportRow;
use App\Models\Subject;
use App\Services\Reference\ReferenceMapper;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Review of a staged import: rows needing attention first (errors, then warnings, then
 * undecided), inline fixes, accept/exclude, publisher spelling merges, bulk accept of
 * clean rows, publish. Only accepted rows go live.
 */
class ReviewReferenceEdition extends Page implements HasTable
{
    use InteractsWithCurrentUser;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ReferenceEditionResource::class;

    /** Sort key: 0 error, 1 warning, 2 undecided, 3 accepted, 4 excluded. */
    private const ATTENTION_ORDER = "CASE WHEN excluded = 1 THEN 4 WHEN resolved = 1 THEN 3 WHEN issues LIKE '%\"error\"%' THEN 0 WHEN issues IS NOT NULL THEN 1 ELSE 2 END";

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(Gate::allows('view', $this->record), 403);
    }

    public function getTitle(): string
    {
        return 'Review: '.$this->edition()->label;
    }

    public function getSubheading(): ?string
    {
        $edition = $this->edition();
        $rows = $edition->importRows();
        $accepted = (clone $rows)->where('resolved', true)->where('excluded', false)->count();
        $excluded = (clone $rows)->where('excluded', true)->count();
        $pending = (clone $rows)->where('resolved', false)->count();

        return "{$edition->rows_total} rows read ({$edition->rows_new} new, {$edition->rows_unchanged} unchanged, {$edition->rows_changed} changed), "
            ."{$edition->rows_removed} removed from the list, {$edition->rows_skipped} skipped. "
            ."Decided: {$accepted} accepted, {$excluded} excluded; {$pending} still to decide.";
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ReferenceImportRow::query()
                ->where('reference_edition_id', $this->edition()->id)
                ->with(['level', 'subject', 'language']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(self::ATTENTION_ORDER)->orderBy('position'))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('decision')
                    ->label('')
                    ->badge()
                    ->state(fn (ReferenceImportRow $record): string => match (true) {
                        $record->excluded => 'Excluded',
                        $record->resolved => 'Accepted',
                        $record->hasErrors() => 'Fix',
                        $record->hasIssues() => 'Check',
                        default => 'To decide',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Fix' => 'danger',
                        'Check' => 'warning',
                        'Accepted' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'info',
                        'changed' => 'warning',
                        'removed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('source_serial')->label('#')->toggleable(),
                TextColumn::make('title')
                    ->wrap()
                    ->searchable()
                    ->description(fn (ReferenceImportRow $record): ?string => $record->changes === null ? null
                        : 'Changed: '.collect($record->changes)->map(fn (array $c, string $field) => $field.' '.json_encode($c[0]).' → '.json_encode($c[1]))->implode('; ')),
                TextColumn::make('level')
                    ->state(fn (ReferenceImportRow $record): ?string => $record->level?->name ?? $record->level_label),
                TextColumn::make('subject')
                    ->state(fn (ReferenceImportRow $record): ?string => $record->subject?->name ?? $record->subject_label),
                TextColumn::make('language.name')->label('Language')->toggleable(),
                TextColumn::make('author')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('publisher_label')->label('Publisher')->wrap()->searchable(),
                TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'low' ? 'warning' : 'gray')
                    ->tooltip('Low: subject, level or language guessed from title keywords.'),
                TextColumn::make('issues')
                    ->label('To check')
                    ->wrap()
                    ->state(fn (ReferenceImportRow $record): ?HtmlString => $record->hasIssues()
                        ? new HtmlString(collect($record->issues)->map(fn (array $i) => e($i['message']))->implode('<br>'))
                        : null),
                TextColumn::make('page')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('decision')
                    ->options([
                        'error' => 'Fix (errors)',
                        'warning' => 'Check (warnings)',
                        'pending' => 'Still to decide',
                        'accepted' => 'Accepted',
                        'excluded' => 'Excluded',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'error' => $query->where('resolved', false)->where('issues', 'like', '%"error"%'),
                        'warning' => $query->where('resolved', false)->whereNotNull('issues')->where('issues', 'not like', '%"error"%'),
                        'pending' => $query->where('resolved', false),
                        'accepted' => $query->where('resolved', true)->where('excluded', false),
                        'excluded' => $query->where('excluded', true),
                        default => $query,
                    }),
                SelectFilter::make('action')->options(['new' => 'New', 'unchanged' => 'Unchanged', 'changed' => 'Changed', 'removed' => 'Removed']),
                SelectFilter::make('category')->options(collect(ReferenceBook::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ReferenceBook::categoryLabel($c)])->all()),
                SelectFilter::make('confidence')->options(['high' => 'High', 'low' => 'Low (guessed)']),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ReferenceImportRow $record): bool => $this->canReview() && ! $record->resolved && ! $record->hasErrors())
                    ->action(fn (ReferenceImportRow $record, Action $action) => DomainErrorNotifier::attempt(
                        fn () => app(ReviewReferenceImport::class)->accept($record), $action,
                    )),
                Action::make('fix')
                    ->label('Fix')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (ReferenceImportRow $record): bool => $this->canReview() && $record->action !== 'removed')
                    ->fillForm(fn (ReferenceImportRow $record): array => $record->only(ReviewReferenceImport::EDITABLE))
                    ->schema([
                        Select::make('category')->required()
                            ->options(collect(ReferenceBook::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ReferenceBook::categoryLabel($c)])->all()),
                        TextInput::make('title')->required()->maxLength(500),
                        Select::make('level_id')->label('Level')->options(fn () => Level::query()->orderBy('level_group_id')->orderBy('sort_order')->pluck('name', 'id')),
                        Select::make('band')->label('Level band')->options([
                            'kg' => 'Creche/Nursery/Kindergarten', 'lower_primary' => 'Lower Primary', 'upper_primary' => 'Upper Primary',
                            'jhs' => 'Junior High School', 'shs' => 'Senior High School',
                        ]),
                        Select::make('subject_id')->label('Subject')->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                        Select::make('language_id')->label('Language')->options(fn () => Language::query()->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('author')->maxLength(300),
                        TextInput::make('publisher_label')->label('Publisher')->required()->maxLength(255)
                            ->helperText('Spelled like a known publisher, it is matched to it.'),
                    ])
                    ->action(function (ReferenceImportRow $record, array $data, Action $action): void {
                        DomainErrorNotifier::attempt(fn () => app(ReviewReferenceImport::class)->update($record, $data), $action);
                        Notification::make()->title($record->fresh()->hasErrors() ? 'Saved, but errors remain' : 'Fixed and accepted')->success()->send();
                    }),
                Action::make('useSuggestion')
                    ->label(fn (ReferenceImportRow $record): string => 'Use "'.($record->issuesWithCode('publisher_similar')[0]['data']['suggestion'] ?? '').'"')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (ReferenceImportRow $record): bool => $this->canReview() && $record->issuesWithCode('publisher_similar') !== [])
                    ->requiresConfirmation()
                    ->modalDescription(fn (ReferenceImportRow $record): string => 'Every row of this import spelled "'.$record->publisher_label
                        .'" will use "'.($record->issuesWithCode('publisher_similar')[0]['data']['suggestion'] ?? '').'". The printed spelling is kept as an alias, so the next list matches it automatically.')
                    ->action(function (ReferenceImportRow $record, Action $action): void {
                        $count = DomainErrorNotifier::attempt(fn () => app(ReviewReferenceImport::class)->applyPublisherSuggestion($record), $action);
                        Notification::make()->title("Publisher merged on {$count} rows")->success()->send();
                    }),
                Action::make('exclude')
                    ->label(fn (ReferenceImportRow $record): string => $record->action === 'removed' ? 'Keep on list' : 'Exclude')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->visible(fn (ReferenceImportRow $record): bool => $this->canReview() && ! $record->excluded)
                    ->action(fn (ReferenceImportRow $record, Action $action) => DomainErrorNotifier::attempt(
                        fn () => app(ReviewReferenceImport::class)->exclude($record), $action,
                    )),
                Action::make('include')
                    ->label('Undo')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (ReferenceImportRow $record): bool => $this->canReview() && ($record->excluded || $record->resolved))
                    ->action(fn (ReferenceImportRow $record, Action $action) => DomainErrorNotifier::attempt(
                        fn () => app(ReviewReferenceImport::class)->include($record), $action,
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('acceptSelected')
                        ->label('Accept selected (rows without errors)')
                        ->icon('heroicon-o-check')
                        ->visible(fn (): bool => $this->canReview())
                        ->action(function (Collection $records): void {
                            $review = app(ReviewReferenceImport::class);
                            $done = $records->reject(fn (ReferenceImportRow $r) => $r->hasErrors())->each(fn (ReferenceImportRow $r) => $review->accept($r))->count();
                            Notification::make()->title("{$done} accepted".($done < $records->count() ? ', '.($records->count() - $done).' with errors skipped' : ''))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('excludeSelected')
                        ->label('Exclude selected')
                        ->icon('heroicon-o-no-symbol')
                        ->visible(fn (): bool => $this->canReview())
                        ->action(function (Collection $records): void {
                            $review = app(ReviewReferenceImport::class);
                            $records->each(fn (ReferenceImportRow $r) => $review->exclude($r));
                            Notification::make()->title($records->count().' excluded')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $acceptAll = fn (string $action, string $label): Action => Action::make('acceptAll'.ucfirst($action))
            ->label($label)
            ->action(function () use ($action): void {
                $count = DomainErrorNotifier::attempt(fn () => app(ReviewReferenceImport::class)->acceptAll($this->edition(), $action));
                Notification::make()->title("{$count} rows accepted")->success()->send();
            });

        return [
            ActionGroup::make([
                $acceptAll('unchanged', 'Accept all unchanged'),
                $acceptAll('new', 'Accept all new rows without issues'),
                $acceptAll('changed', 'Accept all changes without issues'),
                $acceptAll('removed', 'Accept all removals (withdraw)'),
            ])
                ->label('Accept in bulk')
                ->icon('heroicon-o-check-badge')
                ->button()
                ->visible(fn (): bool => $this->canReview()),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn (): bool => Gate::allows('publish', $this->edition()))
                ->requiresConfirmation()
                ->modalHeading('Publish this list?')
                ->modalDescription(fn (): string => $this->publishPreview())
                ->modalSubmitActionLabel('Publish')
                ->action(function (Action $action): void {
                    $summary = DomainErrorNotifier::attempt(fn () => app(PublishReferenceEdition::class)->execute($this->getUser(), $this->edition()), $action);
                    Notification::make()
                        ->title('Approved list published')
                        ->body("{$summary['created']} added, {$summary['updated']} updated, {$summary['unchanged']} unchanged, {$summary['withdrawn']} withdrawn, "
                            ."{$summary['publishers_created']} publishers added. {$summary['pending']} undecided and {$summary['excluded']} excluded rows were left out.")
                        ->success()
                        ->persistent()
                        ->send();
                    $this->redirect(ReferenceEditionResource::getUrl('index'));
                }),
            Action::make('discard')
                ->label('Discard')
                ->color('danger')
                ->outlined()
                ->visible(fn (): bool => Gate::allows('discard', $this->edition()))
                ->requiresConfirmation()
                ->modalDescription('The staged rows and your review decisions are deleted. The live list is not affected.')
                ->action(function (Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(DiscardReferenceEdition::class)->execute($this->getUser(), $this->edition()), $action);
                    Notification::make()->title('Import discarded')->success()->send();
                    $this->redirect(ReferenceEditionResource::getUrl('index'));
                }),
        ];
    }

    private function publishPreview(): string
    {
        $rows = $this->edition()->importRows()->where('resolved', true)->where('excluded', false);
        $by = (clone $rows)->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
        $pending = $this->edition()->importRows()->where('resolved', false)->count();
        $excluded = $this->edition()->importRows()->where('excluded', true)->count();
        $newPublishers = (clone $rows)->where('action', '!=', 'removed')->whereNull('publisher_id')->pluck('publisher_label')
            ->map(fn (string $label) => ReferenceMapper::normalizePublisher($label))->unique()->count();

        return 'Accepted rows go live: '.($by['new'] ?? 0).' new titles, '.($by['changed'] ?? 0).' changed, '
            .($by['unchanged'] ?? 0).' confirmed unchanged, '.($by['removed'] ?? 0).' withdrawn. '
            ."About {$newPublishers} publishers will be added. "
            ."{$pending} undecided and {$excluded} excluded rows are left out. The current live list will be marked superseded.";
    }

    private function canReview(): bool
    {
        return Gate::allows('review', $this->edition());
    }

    private function edition(): ReferenceEdition
    {
        $record = $this->getRecord();
        assert($record instanceof ReferenceEdition);

        return $record;
    }
}
