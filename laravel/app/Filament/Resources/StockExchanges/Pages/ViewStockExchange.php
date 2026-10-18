<?php

namespace App\Filament\Resources\StockExchanges\Pages;

use App\Actions\Inventory\CancelStockExchange;
use App\Actions\Inventory\ConfirmStockExchange;
use App\Actions\Inventory\VoidStockExchange;
use App\Enums\StockExchangeSettlement;
use App\Enums\StockExchangeStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\StockExchanges\StockExchangeResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\StockExchange;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStockExchange extends ViewRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = StockExchangeResource::class;

    protected function getHeaderActions(): array
    {
        $isDraft = fn (StockExchange $record): bool => $record->status === StockExchangeStatus::Draft;
        $isConfirmed = fn (StockExchange $record): bool => $record->status === StockExchangeStatus::Confirmed;

        return [
            EditAction::make()->label('Edit draft')->visible($isDraft),

            Action::make('confirm')
                ->label('Confirm exchange')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible($isDraft)
                ->modalHeading('Confirm exchange')
                ->modalDescription('Creates the invoice for books they took and/or the goods receipt for Taken books.')
                ->fillForm(fn (StockExchange $record): array => [
                    'settlement' => $record->settlement?->value,
                ])
                ->schema(fn (StockExchange $record): array => [
                    Radio::make('settlement')
                        ->label('How they pay (books they took)')
                        ->options([
                            StockExchangeSettlement::Cash->value => StockExchangeSettlement::Cash->label(),
                            StockExchangeSettlement::Installment->value => StockExchangeSettlement::Installment->label(),
                            StockExchangeSettlement::PayLater->value => StockExchangeSettlement::PayLater->label(),
                        ])
                        ->required(),
                    DatePicker::make('due_date')
                        ->helperText('Optional. Blank uses payment terms (except cash = today).'),
                    Toggle::make('apply_credit')
                        ->label('Apply customer credit ('.Money::formatGhsGrouped($record->customer->credit_balance).')')
                        ->visible($record->customer->credit_balance > 0),
                    Toggle::make('override_credit_limit')
                        ->label('Override credit limit')
                        ->visible($record->customer->credit_limit !== null),
                ])
                ->action(function (array $data, StockExchange $record, Action $action): void {
                    $exchange = DomainErrorNotifier::attempt(fn () => app(ConfirmStockExchange::class)->execute($this->getUser(), $record, [
                        'settlement' => $data['settlement'] ?? null,
                        'due_date' => $data['due_date'] ?? null,
                        'apply_credit' => (bool) ($data['apply_credit'] ?? false),
                        'override_credit_limit' => (bool) ($data['override_credit_limit'] ?? false),
                    ]), $action, $record);
                    $this->record->refresh();

                    Notification::make()
                        ->title('Exchange confirmed')
                        ->body($exchange->exchange_no.($exchange->sale?->invoice_no ? ' · '.$exchange->sale->invoice_no : ''))
                        ->success()
                        ->send();

                    if ($exchange->sale_id && $exchange->settlement === StockExchangeSettlement::Cash) {
                        Notification::make()
                            ->title('Record the cash payment')
                            ->body('Open the sale or Record payment to collect cash and carry.')
                            ->info()
                            ->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel draft')
                ->color('gray')
                ->visible($isDraft)
                ->requiresConfirmation()
                ->action(function (StockExchange $record, Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(CancelStockExchange::class)->execute($this->getUser(), $record), $action);
                    Notification::make()->title('Draft cancelled')->success()->send();
                    $this->redirect(StockExchangeResource::getUrl('index'));
                }),

            Action::make('openSale')
                ->label('Open invoice / payments')
                ->icon('heroicon-o-document-text')
                ->visible(fn (StockExchange $record): bool => $record->sale_id !== null)
                ->url(fn (StockExchange $record): string => SaleResource::getUrl('view', ['record' => $record->sale_id])),

            Action::make('recordPayment')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (StockExchange $record): bool => $record->sale_id !== null
                    && $record->sale
                    && $record->sale->balance_due > 0)
                ->url(fn (StockExchange $record): string => PaymentResource::getUrl('create', [
                    'customer_id' => $record->customer_id,
                ])),

            Action::make('void')
                ->label('Void exchange')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible($isConfirmed)
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(function (array $data, StockExchange $record, Action $action): void {
                    DomainErrorNotifier::attempt(
                        fn () => app(VoidStockExchange::class)->execute($this->getUser(), $record, $data['reason']),
                        $action,
                    );
                    $this->record->refresh();
                    Notification::make()->title('Exchange voided')->success()->send();
                }),
        ];
    }
}
