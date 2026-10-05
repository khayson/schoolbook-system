<?php

namespace App\Filament\Resources\ReferenceBooks;

use App\Filament\Resources\ReferenceBooks\Pages\ListReferenceBooks;
use App\Models\ReferenceBook;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The live approved list, read-only. It changes only by publishing a reviewed import.
 */
class ReferenceBookResource extends Resource
{
    protected static ?string $model = ReferenceBook::class;

    protected static ?string $navigationLabel = 'Approved titles';

    protected static ?string $modelLabel = 'approved title';

    protected static string|\UnitEnum|null $navigationGroup = 'Approved list';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $recordTitleAttribute = 'title';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->columnSpanFull(),
            TextEntry::make('category')->formatStateUsing(fn (string $state): string => ReferenceBook::categoryLabel($state)),
            TextEntry::make('status')->badge()->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),
            TextEntry::make('level_label')->label('Level (as printed)')->placeholder('—'),
            TextEntry::make('level.name')->label('Level')->placeholder('—'),
            TextEntry::make('subject.name')->label('Subject')->placeholder('—'),
            TextEntry::make('language.name')->label('Language')->placeholder('—'),
            TextEntry::make('author')->placeholder('—'),
            TextEntry::make('publisher_label')->label('Publisher (as printed)'),
            TextEntry::make('publisher.name')->label('Publisher'),
            TextEntry::make('isbn')->label('ISBN')->placeholder('Not known yet'),
            TextEntry::make('confidence')->helperText('Low: subject, level or language guessed from title keywords.'),
            TextEntry::make('firstSeenEdition.label')->label('First listed in'),
            TextEntry::make('lastSeenEdition.label')->label('Last listed in'),
            TextEntry::make('source_serial')->label('Serial in last list'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->searchable(['title', 'search_title'])->sortable()->wrap(),
                TextColumn::make('category')->badge()->formatStateUsing(fn (string $state): string => ReferenceBook::categoryLabel($state)),
                TextColumn::make('level')
                    ->state(fn (ReferenceBook $record): ?string => $record->level?->name ?? $record->level_label),
                TextColumn::make('subject.name')->label('Subject')->placeholder('—'),
                TextColumn::make('language.name')->label('Language')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('publisher.name')->label('Publisher')->searchable()->wrap(),
                TextColumn::make('author')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('products_count')->label('My products')->counts('products')->numeric(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['approved' => 'Approved', 'withdrawn' => 'Withdrawn'])->default('approved'),
                SelectFilter::make('category')->options(collect(ReferenceBook::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ReferenceBook::categoryLabel($c)])->all()),
                SelectFilter::make('level_id')->label('Level')->relationship('level', 'name')->preload(),
                SelectFilter::make('subject_id')->label('Subject')->relationship('subject', 'name')->preload(),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make(), ReferenceBookActions::addToProducts()])
            ->toolbarActions([BulkActionGroup::make([ReferenceBookActions::csvTemplate()])]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['level', 'subject', 'language', 'publisher']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferenceBooks::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
