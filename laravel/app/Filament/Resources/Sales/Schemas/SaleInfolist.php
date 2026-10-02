<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Services\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $schema->components([
            Section::make('Sale')
                ->columns(4)
                ->schema([
                    TextEntry::make('invoice_no')->label('Invoice no.')->placeholder('Draft'),
                    TextEntry::make('customer.name')->label('Customer'),
                    TextEntry::make('status')->badge()->color(fn ($state): string => self::statusColor($state)),
                    TextEntry::make('payment_status')->label('Payment')->badge()
                        ->color(fn ($state): string => match ($state) {
                            PaymentStatus::Paid => 'success',
                            PaymentStatus::Partial => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('sale_date')->date(),
                    TextEntry::make('due_date')->date()->placeholder('-'),
                    TextEntry::make('confirmed_at')->dateTime()->placeholder('-'),
                    TextEntry::make('delivered_at')->dateTime()->placeholder('Not delivered'),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Totals')
                ->columns(4)
                ->schema([
                    TextEntry::make('subtotal')->formatStateUsing($ghs),
                    TextEntry::make('total')->formatStateUsing($ghs),
                    TextEntry::make('amount_paid')->label('Paid')->formatStateUsing($ghs),
                    TextEntry::make('balance_due')->label('Balance due')->formatStateUsing($ghs)->weight('bold'),
                ]),
            Section::make('Books')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('product_title')->label('Book')->columnSpan(2),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit_price')->label('Unit price')->formatStateUsing($ghs)
                                ->helperText(fn ($record): ?string => $record?->is_price_overridden
                                    ? 'Override (list '.Money::formatGhsGrouped($record->base_price)."): {$record->override_reason}"
                                    : null),
                            TextEntry::make('line_total')->label('Line total')->formatStateUsing($ghs),
                        ]),
                ]),
            Section::make('Payments applied')
                ->description('Allocation ledger: negative rows reverse an earlier allocation.')
                ->visible(fn ($record): bool => $record->status !== SaleStatus::Draft)
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('payment.receipt_no')->label('Receipt'),
                            TextEntry::make('amount')->formatStateUsing($ghs),
                            TextEntry::make('reversal_of_id')->label('Type')
                                ->formatStateUsing(fn (?int $state): string => $state === null ? 'Allocation' : "Reversal of #{$state}")
                                ->placeholder('Allocation'),
                            TextEntry::make('created_at')->dateTime(),
                        ])
                        ->placeholder('No payments yet.'),
                ]),
            Section::make('Cancelled / void')
                ->columns(3)
                ->visible(fn ($record): bool => in_array($record->status, [SaleStatus::Cancelled, SaleStatus::Void], true))
                ->schema([
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('-'),
                    TextEntry::make('cancel_reason')->placeholder('-'),
                    TextEntry::make('voided_at')->dateTime()->placeholder('-'),
                    TextEntry::make('voidedBy.name')->label('Voided by')->placeholder('-'),
                    TextEntry::make('void_reason')->placeholder('-')->columnSpan(2),
                ]),
        ]);
    }

    public static function statusColor(mixed $state): string
    {
        return match ($state) {
            SaleStatus::Confirmed => 'success',
            SaleStatus::Draft, SaleStatus::Requested => 'warning',
            SaleStatus::Void => 'danger',
            default => 'gray',
        };
    }
}
