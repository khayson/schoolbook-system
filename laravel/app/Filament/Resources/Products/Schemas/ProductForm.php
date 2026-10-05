<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\ReferenceBook;
use App\Services\Money;
use App\Services\Reference\ReferenceBookSearch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->helperText(fn (string $operation): ?string => $operation === 'create' ? 'Blank: generated (BK-000123).' : null)
                    ->maxLength(255),
                TextInput::make('isbn')
                    ->label('ISBN')
                    ->maxLength(255),
                TextInput::make('barcode')
                    ->maxLength(255),
                Select::make('reference_book_id')
                    ->label('Approved list title')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(ReferenceBookSearch::class)
                        ->apply(ReferenceBook::query()->approved(), $search)
                        ->limit(30)->get()
                        ->mapWithKeys(fn (ReferenceBook $b) => [$b->id => self::bookLabel($b)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($b = ReferenceBook::query()->find($value)) ? self::bookLabel($b) : null)
                    ->helperText('Search like "sunrise maths basic 2". Leave empty for books not on the NaCCA list.')
                    ->columnSpanFull(),
                TextInput::make('variant_label')
                    ->label('Variant')
                    ->placeholder("Learner's Book, Teacher's Guide, Workbook…")
                    ->maxLength(100),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('level_id')
                    ->relationship('level', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('subject_id')
                    ->relationship('subject', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('language_id')
                    ->relationship('language', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('publisher_id')
                    ->relationship('publisher', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('edition')
                    ->maxLength(255),
                TextInput::make('cost_price')
                    ->label('Cost price (GHS)')
                    ->numeric()
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? Money::pesewasToGhs($state) : null)
                    ->dehydrateStateUsing(fn (?string $state): int => Money::ghsToPesewas($state)),
                TextInput::make('selling_price')
                    ->label('Selling price (GHS)')
                    ->numeric()
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? Money::pesewasToGhs($state) : null)
                    ->dehydrateStateUsing(fn (?string $state): int => Money::ghsToPesewas($state)),
                TextInput::make('reorder_level')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }

    private static function bookLabel(ReferenceBook $book): string
    {
        $book->loadMissing(['level', 'publisher']);

        return $book->title.' · '.($book->level?->name ?? $book->level_label ?? ReferenceBook::categoryLabel($book->category))
            .' · '.($book->publisher?->name ?? $book->publisher_label).($book->status === 'withdrawn' ? ' (withdrawn)' : '');
    }
}
