<?php

namespace App\Filament\Resources\StockExchanges\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockExchangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('exchange_no')
                    ->label('Exchange')
                    ->searchable()
                    ->placeholder('Draft')
                    ->sortable(),
                TextColumn::make('counterparty_name')
                    ->label('Other person')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->label('Dealer')
                    ->searchable(),
                TextColumn::make('settlement')
                    ->label('Pay')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sale.invoice_no')
                    ->label('Invoice')
                    ->placeholder('—'),
                TextColumn::make('goodsReceipt.receipt_no')
                    ->label('Taken GRN')
                    ->placeholder('—'),
                TextColumn::make('confirmed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Created by'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'confirmed' => 'Confirmed',
                        'void' => 'Void',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
