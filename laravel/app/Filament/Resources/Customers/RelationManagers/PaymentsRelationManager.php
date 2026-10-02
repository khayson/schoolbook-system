<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list; payments are recorded and voided through their actions.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $table
            ->recordTitleAttribute('receipt_no')
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('receipt_no')->label('Receipt')->searchable(),
                TextColumn::make('paid_at')->dateTime('d M Y H:i'),
                TextColumn::make('amount')->formatStateUsing($ghs)->alignEnd(),
                TextColumn::make('unallocated_amount')->label('As credit')->formatStateUsing($ghs)->alignEnd(),
                TextColumn::make('method')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
