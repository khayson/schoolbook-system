<?php

namespace App\Filament\Resources\GoodsReceipts\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GoodsReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('receipt_no')
                    ->label('Receipt')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('—'),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Received by'),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Lines'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
