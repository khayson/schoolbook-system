<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Product;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('sale_date')
                    ->required()
                    ->default(now()),
                DatePicker::make('due_date'),
                Textarea::make('notes')->columnSpanFull(),
                Repeater::make('items')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(fn (): array => Product::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(1),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->columnSpanFull(),
            ]);
    }
}
