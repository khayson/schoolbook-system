<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\StockMovementType;
use App\Services\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (StockMovementType $state): string => str($state->value)->replace('_', ' ')->title()->toString()),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('balance_after')
                    ->label('Balance')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Unit cost')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? Money::formatGhs($state) : '—'),
                TextColumn::make('user.name')
                    ->label('User'),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'title')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->options(collect(StockMovementType::cases())->mapWithKeys(
                        fn (StockMovementType $type): array => [$type->value => str($type->value)->replace('_', ' ')->title()->toString()],
                    )->all()),
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
