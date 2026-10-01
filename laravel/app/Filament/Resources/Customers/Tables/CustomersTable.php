<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Services\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('region')->toggleable(),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('credit_balance')
                    ->label('Credit')
                    ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state ?? 0)),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'school' => 'School',
                        'reseller' => 'Reseller',
                        'individual' => 'Individual',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
