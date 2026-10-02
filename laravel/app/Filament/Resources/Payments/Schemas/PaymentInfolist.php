<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Services\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $schema->components([
            Section::make('Payment')
                ->columns(3)
                ->schema([
                    TextEntry::make('receipt_no')->label('Receipt no.'),
                    TextEntry::make('customer.name')->label('Customer'),
                    TextEntry::make('status')->badge()->color(fn ($state): string => $state?->value === 'void' ? 'danger' : 'success'),
                    TextEntry::make('amount')->formatStateUsing($ghs),
                    TextEntry::make('unallocated_amount')->label('Held as credit')->formatStateUsing($ghs),
                    TextEntry::make('method')->formatStateUsing(fn ($state): string => str($state?->value)->headline()->toString()),
                    TextEntry::make('reference')->placeholder('-'),
                    TextEntry::make('paid_at')->dateTime(),
                    TextEntry::make('receivedBy.name')->label('Received by'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Void')
                ->columns(3)
                ->visible(fn ($record): bool => ! $record->isValid())
                ->schema([
                    TextEntry::make('voided_at')->dateTime(),
                    TextEntry::make('voidedBy.name')->label('Voided by'),
                    TextEntry::make('void_reason')->columnSpanFull(),
                ]),
            Section::make('Allocation ledger')
                ->description('Rows are never edited. Negative rows reverse an earlier allocation.')
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('sale.invoice_no')->label('Invoice'),
                            TextEntry::make('amount')->formatStateUsing($ghs),
                            TextEntry::make('reversal_of_id')
                                ->label('Type')
                                ->formatStateUsing(fn (?int $state): string => $state === null ? 'Allocation' : "Reversal of #{$state}")
                                ->placeholder('Allocation'),
                            TextEntry::make('created_at')->dateTime(),
                        ])
                        ->placeholder('Not applied to any invoice.'),
                ]),
        ]);
    }
}
