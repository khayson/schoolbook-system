<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Enums\StockMovementType;
use App\Services\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('occurred_at')
                    ->dateTime(),
                TextEntry::make('product.title')
                    ->label('Product'),
                TextEntry::make('type')
                    ->formatStateUsing(fn (StockMovementType $state): string => str($state->value)->replace('_', ' ')->title()->toString()),
                TextEntry::make('quantity'),
                TextEntry::make('balance_after')
                    ->label('Balance after'),
                TextEntry::make('unit_cost')
                    ->label('Unit cost')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? Money::formatGhs($state) : '—'),
                TextEntry::make('note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('user.name')
                    ->label('Recorded by'),
            ]);
    }
}
