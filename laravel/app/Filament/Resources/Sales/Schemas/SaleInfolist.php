<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Services\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('invoice_no')->placeholder('Draft'),
                TextEntry::make('customer.name')->label('Customer'),
                TextEntry::make('status')->badge(),
                TextEntry::make('sale_date')->date(),
                TextEntry::make('total')
                    ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state ?? 0)),
                TextEntry::make('notes')->columnSpanFull(),
            ]);
    }
}
