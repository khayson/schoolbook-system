<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Services\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('invoice_no')->placeholder('Draft')->searchable(),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('sale_date')->date()->sortable(),
                TextColumn::make('total')
                    ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state ?? 0)),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'confirmed' => 'Confirmed',
                        'cancelled' => 'Cancelled',
                        'void' => 'Void',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
