<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Models\Product;
use App\Services\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GoodsReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('supplier_reference')
                    ->maxLength(255),
                DateTimePicker::make('received_at')
                    ->required()
                    ->default(now()),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Repeater::make('items')
                    ->label('Products')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(fn (): array => Product::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('unit_cost')
                            ->label('Unit cost (GHS)')
                            ->numeric()
                            ->required()
                            ->dehydrateStateUsing(function ($state): int {
                                if (is_int($state) || is_string($state)) {
                                    return Money::ghsToPesewas($state);
                                }

                                // Livewire may pass a numeric float; stringify without * 100.
                                return Money::ghsToPesewas(
                                    rtrim(rtrim(sprintf('%.2F', $state), '0'), '.') ?: '0',
                                );
                            }),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->columnSpanFull(),
            ]);
    }
}
