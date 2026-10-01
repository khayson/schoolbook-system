<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Services\Money;
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
                    ->required()
                    ->maxLength(255),
                TextInput::make('isbn')
                    ->label('ISBN')
                    ->maxLength(255),
                TextInput::make('barcode')
                    ->maxLength(255),
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
}
