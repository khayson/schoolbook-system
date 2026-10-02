<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\MarkDelivered;
use App\Actions\Sales\RenderInvoicePdf;
use App\Actions\Sales\UpdateDraftSale;
use App\Actions\Sales\VoidSale;
use App\Enums\SaleStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\Sale;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every button calls an Action class; business errors become notifications through
 * DomainErrorNotifier (modal stays open, nothing written).
 */
class ViewSale extends ViewRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        $isDraft = fn (Sale $record): bool => $record->status === SaleStatus::Draft;
        $isOpenConfirmed = fn (Sale $record): bool => $record->status === SaleStatus::Confirmed && $record->delivered_at === null;

        return [
            EditAction::make()->label('Edit draft')->visible($isDraft),

            Action::make('reprice')
                ->label('Re-price draft')
                ->icon('heroicon-o-arrow-path')
                ->visible($isDraft)
                ->requiresConfirmation()
                ->modalDescription('Prices every line again at current prices (overrides are kept). Use this after a "Prices changed" message.')
                ->action(function (Sale $record, Action $action): void {
                    $before = $record->total;
                    $sale = DomainErrorNotifier::attempt(fn () => app(UpdateDraftSale::class)->execute($this->getUser(), $record, []), $action);
                    $this->record->refresh();

                    Notification::make()
                        ->title('Draft re-priced')
                        ->body('Total '.Money::formatGhsGrouped($before).' -> '.Money::formatGhsGrouped($sale->total))
                        ->success()
                        ->send();
                }),

            Action::make('confirm')
                ->label('Confirm sale')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible($isDraft)
                ->modalDescription('Issues the invoice number and takes the books out of stock.')
                ->fillForm(fn (Sale $record): array => ['due_date' => $record->due_date])
                ->schema(fn (Sale $record): array => [
                    DatePicker::make('due_date')->helperText('Leave empty to use the payment terms from settings.'),
                    Toggle::make('apply_credit')
                        ->label('Apply customer credit ('.Money::formatGhsGrouped($record->customer->credit_balance).')')
                        ->visible($record->customer->credit_balance > 0),
                    Toggle::make('override_credit_limit')
                        ->label('Override credit limit')
                        ->helperText('Only if you were warned that this sale exceeds the customer\'s credit limit.')
                        ->visible($record->customer->credit_limit !== null),
                ])
                ->action(function (array $data, Sale $record, Action $action): void {
                    $sale = DomainErrorNotifier::attempt(fn () => app(ConfirmSale::class)->execute($this->getUser(), $record, [
                        'due_date' => $data['due_date'] ?? null,
                        'apply_credit' => (bool) ($data['apply_credit'] ?? false),
                        'override_credit_limit' => (bool) ($data['override_credit_limit'] ?? false),
                    ]), $action, $record);
                    $this->record->refresh();

                    Notification::make()
                        ->title("Invoice {$sale->invoice_no} issued")
                        ->body('Total '.Money::formatGhsGrouped($sale->total).', balance due '.Money::formatGhsGrouped($sale->balance_due))
                        ->success()
                        ->send();
                }),

            Action::make('cancel')
                ->label('Cancel draft')
                ->color('gray')
                ->visible($isDraft)
                ->schema([Textarea::make('reason')->maxLength(1000)])
                ->action(function (array $data, Sale $record, Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(CancelSale::class)->execute($this->getUser(), $record, $data['reason'] ?? null), $action);
                    $this->record->refresh();
                    Notification::make()->title('Draft cancelled')->success()->send();
                }),

            Action::make('deliver')
                ->label('Mark delivered')
                ->icon('heroicon-o-truck')
                ->visible($isOpenConfirmed)
                ->requiresConfirmation()
                ->modalDescription('After delivery the invoice can no longer be voided.')
                ->action(function (Sale $record, Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(MarkDelivered::class)->execute($this->getUser(), $record), $action);
                    $this->record->refresh();
                    Notification::make()->title('Marked as delivered')->success()->send();
                }),

            Action::make('void')
                ->label('Void invoice')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible($isOpenConfirmed)
                ->modalDescription('Puts the books back in stock. Any payments on this invoice become customer credit. This cannot be undone.')
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(function (array $data, Sale $record, Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(VoidSale::class)->execute($this->getUser(), $record, $data['reason']), $action);
                    $this->record->refresh();
                    Notification::make()->title("Invoice {$record->invoice_no} voided")->success()->send();
                }),

            Action::make('recordPayment')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (Sale $record): bool => $record->status === SaleStatus::Confirmed && $record->balance_due > 0)
                ->url(fn (Sale $record): string => PaymentResource::getUrl('create', ['customer_id' => $record->customer_id])),

            Action::make('downloadInvoice')
                ->label('Download invoice')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (Sale $record): bool => $record->status === SaleStatus::Confirmed)
                ->action(fn (Sale $record): StreamedResponse => self::download($record)),
        ];
    }

    public static function download(Sale $sale): StreamedResponse
    {
        $render = app(RenderInvoicePdf::class);
        $pdf = DomainErrorNotifier::attempt(fn () => $render->execute($sale));

        return response()->streamDownload(fn () => print ($pdf->output()), $render->filename($sale), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
