<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Services\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('level.levelGroup.name')
                    ->label('Group')
                    ->toggleable(),
                TextColumn::make('level.name')
                    ->label('Level')
                    ->sortable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('language.name')
                    ->label('Language')
                    ->sortable(),
                TextColumn::make('publisher.name')
                    ->label('Publisher')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_price')
                    ->label('Cost')
                    ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state))
                    ->sortable(),
                TextColumn::make('selling_price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => Money::formatGhs($state))
                    ->sortable(),
                TextColumn::make('stock_on_hand')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (Product $record): string => $record->stock_on_hand <= $record->reorder_level ? 'danger' : 'success')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('level_group_id')
                    ->label('Level group')
                    ->relationship('level.levelGroup', 'name'),
                SelectFilter::make('level_id')
                    ->label('Level')
                    ->relationship('level', 'name'),
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->relationship('subject', 'name'),
                SelectFilter::make('language_id')
                    ->label('Language')
                    ->relationship('language', 'name'),
                SelectFilter::make('publisher_id')
                    ->label('Publisher')
                    ->relationship('publisher', 'name'),
                Filter::make('low_stock')
                    ->label('Low stock')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock_on_hand', '<=', 'reorder_level')),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
