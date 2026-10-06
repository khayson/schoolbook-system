<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\RenderStatementPdf;
use App\Actions\Payments\AllocateCredit;
use App\Actions\Payments\RecordPayment;
use App\Actions\Sales\CreateOpeningBalance;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\GhsInput;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer details are edited here; money moves only through RecordPayment and
 * AllocateCredit. Customers are deactivated, never deleted.
 */
class EditCustomer extends EditRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->modalHeading(fn (Customer $record): string => "Record payment from {$record->name}")
                ->modalDescription(fn (Customer $record): string => PaymentForm::balancesLine($record->id) ?? '')
                ->schema(fn (Customer $record): array => PaymentForm::fields(fn (): int => $record->id))
                ->action(function (array $data, Customer $record, Action $action): void {
                    $payment = DomainErrorNotifier::attempt(
                        fn () => app(RecordPayment::class)->execute($this->getUser(), PaymentForm::toActionData($data, $record->id)),
                        $action,
                    );
                    $this->refreshBalances();

                    Notification::make()
                        ->title("Payment {$payment->receipt_no} recorded")
                        ->body('Applied '.Money::formatGhsGrouped($payment->amount - $payment->unallocated_amount)
                            .', kept as credit '.Money::formatGhsGrouped($payment->unallocated_amount))
                        ->success()
                        ->send();
                }),

            Action::make('applyCredit')
                ->label('Apply credit')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (Customer $record): bool => $record->credit_balance > 0)
                ->modalDescription(fn (Customer $record): string => 'Available credit '.Money::formatGhsGrouped($record->credit_balance).'. Drawn from the oldest payments first.')
                ->schema(fn (Customer $record): array => [
                    Radio::make('mode')
                        ->options(['oldest' => 'Oldest invoices first', 'choose' => 'Invoices I choose'])
                        ->default('oldest')
                        ->required()
                        ->live(),
                    Repeater::make('allocations')
                        ->label('Invoices')
                        ->schema([
                            Select::make('sale_id')->label('Invoice')->options(PaymentForm::openInvoiceOptions($record->id))->required(),
                            GhsInput::make('amount')->required(),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->visible(fn (Get $get): bool => $get('mode') === 'choose'),
                ])
                ->action(function (array $data, Customer $record, Action $action): void {
                    $allocations = ($data['mode'] ?? 'oldest') === 'choose'
                        ? array_values(array_map(fn (array $row): array => ['sale_id' => (int) $row['sale_id'], 'amount' => (int) $row['amount']], $data['allocations'] ?? []))
                        : null;

                    $result = DomainErrorNotifier::attempt(
                        fn () => app(AllocateCredit::class)->execute($this->getUser(), $record, $allocations),
                        $action,
                    );
                    $this->refreshBalances();

                    Notification::make()
                        ->title('Credit applied: '.Money::formatGhsGrouped($result->appliedTotal))
                        ->body('Credit left '.Money::formatGhsGrouped($result->customer->credit_balance))
                        ->success()
                        ->send();
                }),

            Action::make('openingBalance')
                ->label('Opening balance')
                ->icon('heroicon-o-arrow-uturn-right')
                ->visible(fn (Customer $record): bool => ! Sale::query()->where('customer_id', $record->id)->where('is_opening_balance', true)->where('status', '<>', 'void')->exists())
                ->modalHeading(fn (Customer $record): string => "Opening balance for {$record->name}")
                ->modalDescription('What this customer owed before the system (paper ledger). It counts in what they owe, statements and aging, never in sales or profit. One per customer; void it to replace it.')
                ->schema([
                    GhsInput::make('amount')->label('Amount owed')->required(),
                    DatePicker::make('date')->label('Owed since')->native(false)->displayFormat('d M Y')->format('Y-m-d')->required()->maxDate(now())
                        ->default(fn (): string => now()->toDateString()),
                    DatePicker::make('due_date')->label('Due date')->native(false)->displayFormat('d M Y')->format('Y-m-d')->required(),
                ])
                ->action(function (array $data, Customer $record, Action $action): void {
                    $sale = DomainErrorNotifier::attempt(
                        fn () => app(CreateOpeningBalance::class)->execute($this->getUser(), $record, [
                            'amount' => (int) $data['amount'],
                            'date' => $data['date'],
                            'due_date' => $data['due_date'],
                        ]),
                        $action,
                    );
                    $this->refreshBalances();
                    Notification::make()->title("Opening balance {$sale->invoice_no} recorded: ".Money::formatGhsGrouped($sale->total))->success()->send();
                }),

            Action::make('statement')
                ->label('Statement')
                ->icon('heroicon-o-document-text')
                ->modalHeading(fn (Customer $record): string => "Statement for {$record->name}")
                ->modalSubmitActionLabel('Download PDF')
                ->schema([
                    DatePicker::make('from')->native(false)->displayFormat('d M Y')->format('Y-m-d')->required()
                        ->default(fn (): string => now()->startOfMonth()->toDateString()),
                    DatePicker::make('to')->native(false)->displayFormat('d M Y')->format('Y-m-d')->required()->afterOrEqual('from')
                        ->default(fn (): string => now()->toDateString()),
                ])
                ->action(fn (array $data, Customer $record): StreamedResponse => self::statement($record, $data['from'], $data['to'])),

            Action::make('deactivate')
                ->label('Deactivate')
                ->color('danger')
                ->icon('heroicon-o-pause-circle')
                ->visible(fn (Customer $record): bool => $record->is_active)
                ->requiresConfirmation()
                ->modalDescription('The customer stays on record with all invoices and payments, and can still pay. New sales are blocked until reactivated.')
                ->action(function (Customer $record): void {
                    $record->update(['is_active' => false]);
                    $this->refreshFormData(['is_active']);
                    Notification::make()->title("{$record->name} deactivated")->success()->send();
                }),

            Action::make('reactivate')
                ->label('Reactivate')
                ->icon('heroicon-o-play-circle')
                ->visible(fn (Customer $record): bool => ! $record->is_active)
                ->action(function (Customer $record): void {
                    $record->update(['is_active' => true]);
                    $this->refreshFormData(['is_active']);
                    Notification::make()->title("{$record->name} reactivated")->success()->send();
                }),
        ];
    }

    public static function statement(Customer $customer, string $from, string $to): StreamedResponse
    {
        $render = app(RenderStatementPdf::class);
        $pdf = $render->execute($customer, $from, $to);

        return response()->streamDownload(fn () => print ($pdf->output()), $render->filename($customer, $from, $to), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function refreshBalances(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['credit_balance_display', 'outstanding_balance_display']);
    }
}
