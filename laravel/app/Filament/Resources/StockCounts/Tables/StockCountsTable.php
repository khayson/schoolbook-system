<?php

namespace App\Filament\Resources\StockCounts\Tables;

use App\Filament\Resources\StockCounts\Schemas\StockCountInfolist;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Models\StockCount;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockCountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')]))
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => StockCountResource::statusColor($state)),
                TextColumn::make('filters')->label('Products')->state(fn (StockCount $record): string => StockCountInfolist::describeFilters($record->filters)),
                TextColumn::make('progress')->label('Counted')->state(fn (StockCount $record): string => "{$record->counted_count} of {$record->items_count}"),
                TextColumn::make('created_at')->label('Opened')->dateTime(),
                TextColumn::make('applied_at')->dateTime()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'applied' => 'Applied', 'cancelled' => 'Cancelled']),
            ]);
    }
}
