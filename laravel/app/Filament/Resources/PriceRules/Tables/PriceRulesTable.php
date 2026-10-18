<?php

namespace App\Filament\Resources\PriceRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PriceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('value')->sortable(),
                TextColumn::make('scope_type')->label('Scope'),
                TextColumn::make('customer_scope')->label('Customer'),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('stackable')->boolean(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('floor_policy')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'percent_off' => 'percent off',
                        'amount_off' => 'amount off',
                        'fixed_price' => 'fixed price',
                        'order_percent_off' => 'order percent off',
                        'order_amount_off' => 'order amount off',
                        'bonus_units' => 'bonus units',
                    ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
