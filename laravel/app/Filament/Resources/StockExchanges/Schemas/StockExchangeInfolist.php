<?php

namespace App\Filament\Resources\StockExchanges\Schemas;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Services\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockExchangeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who')
                    ->schema([
                        TextEntry::make('exchange_no')->label('Exchange')->placeholder('Draft'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('customer.name')->label('Person / dealer'),
                        TextEntry::make('customer.type')
                            ->label('Type')
                            ->formatStateUsing(fn ($state) => $state ? ucfirst((string) (is_object($state) ? $state->value : $state)) : '—'),
                        TextEntry::make('counterparty_name')->label('Paper / list name'),
                        TextEntry::make('settlement')
                            ->label('Settlement')
                            ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                    ])
                    ->columns(3),

                Section::make('Books')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('direction')
                                    ->label('Side')
                                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                                TextEntry::make('product.title')->label('Book'),
                                TextEntry::make('quantity')->label('How many'),
                                TextEntry::make('unit_price')
                                    ->label('Selling price')
                                    ->formatStateUsing(fn ($state) => $state !== null ? Money::formatGhsGrouped((int) $state) : '—'),
                                TextEntry::make('unit_cost')
                                    ->label('Cost')
                                    ->formatStateUsing(fn ($state) => $state !== null ? Money::formatGhsGrouped((int) $state) : '—'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),

                Section::make('After confirm')
                    ->schema([
                        TextEntry::make('sale.invoice_no')
                            ->label('Invoice (they took)')
                            ->url(fn ($record) => $record->sale_id
                                ? SaleResource::getUrl('view', ['record' => $record->sale_id])
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('goodsReceipt.receipt_no')
                            ->label('Goods receipt (Taken)')
                            ->url(fn ($record) => $record->goods_receipt_id
                                ? GoodsReceiptResource::getUrl('view', ['record' => $record->goods_receipt_id])
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
