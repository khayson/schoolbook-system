<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Services\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GoodsReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('receipt_no')
                    ->label('Receipt no.'),
                TextEntry::make('supplier.name')
                    ->label('Supplier'),
                TextEntry::make('supplier_reference'),
                TextEntry::make('received_at')
                    ->dateTime(),
                TextEntry::make('notes')
                    ->columnSpanFull(),
                TextEntry::make('createdBy.name')
                    ->label('Received by'),
                RepeatableEntry::make('items')
                    ->label('Lines')
                    ->schema([
                        TextEntry::make('product.title')
                            ->label('Product'),
                        TextEntry::make('quantity'),
                        TextEntry::make('unit_cost')
                            ->label('Unit cost')
                            ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state)),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
