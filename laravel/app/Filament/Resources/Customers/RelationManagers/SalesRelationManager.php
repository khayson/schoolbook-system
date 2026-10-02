<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Sales\Schemas\SaleInfolist;
use App\Models\Sale;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list; sales are created and changed from the Sales resource.
 */
class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $table
            ->recordTitleAttribute('invoice_no')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice')->placeholder('Draft')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => SaleInfolist::statusColor($state)),
                TextColumn::make('payment_status')->label('Payment')->badge(),
                TextColumn::make('sale_date')->date(),
                TextColumn::make('due_date')->date()->placeholder('-'),
                TextColumn::make('total')->formatStateUsing($ghs)->alignEnd(),
                TextColumn::make('balance_due')->label('Balance')->formatStateUsing($ghs)->alignEnd(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
