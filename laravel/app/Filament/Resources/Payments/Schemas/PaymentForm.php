<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Filament\Support\GhsInput;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Money;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The record-payment form, used by the Payments create page and by the "Record payment"
 * action on a customer. Submission always goes through RecordPayment (toActionData).
 */
class PaymentForm
{
    public const MODE_OLDEST = 'oldest';

    public const MODE_CHOOSE = 'choose';

    public const MODE_CREDIT = 'credit';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Customer')
                ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->helperText(fn (Get $get): ?string => self::balancesLine($get('customer_id'))),
            ...self::fields(fn (Get $get): ?int => self::intOrNull($get('../../customer_id'))),
        ]);
    }

    /**
     * Payment fields without the customer picker.
     *
     * @param  Closure(Get): ?int  $customerId  resolves the customer from inside the allocation repeater
     * @return list<Component>
     */
    public static function fields(Closure $customerId): array
    {
        return [
            GhsInput::make('amount')->label('Amount')->required(),
            Select::make('method')
                ->options([
                    PaymentMethod::Cash->value => 'Cash',
                    PaymentMethod::Momo->value => 'Mobile Money',
                    PaymentMethod::BankTransfer->value => 'Bank transfer',
                    PaymentMethod::Cheque->value => 'Cheque',
                ])
                ->default(PaymentMethod::Cash->value)
                ->required()
                ->live(),
            TextInput::make('reference')
                ->maxLength(100)
                ->required(fn (Get $get): bool => $get('method') !== PaymentMethod::Cash->value)
                ->helperText('Required for Mobile Money, bank transfer and cheque: the transaction or cheque number.'),
            DateTimePicker::make('paid_at')
                ->label('Paid at')
                ->default(fn () => now())
                ->maxDate(fn () => now())
                ->required(),
            Textarea::make('notes')->maxLength(1000)->columnSpanFull(),
            Radio::make('allocation_mode')
                ->label('Apply the money')
                ->options([
                    self::MODE_OLDEST => 'To the oldest invoices first',
                    self::MODE_CHOOSE => 'To invoices I choose',
                    self::MODE_CREDIT => 'Keep it all as customer credit',
                ])
                ->default(self::MODE_OLDEST)
                ->required()
                ->live()
                ->columnSpanFull(),
            Repeater::make('allocations')
                ->label('Invoices')
                ->schema([
                    Select::make('sale_id')
                        ->label('Invoice')
                        ->options(fn (Get $get): array => self::openInvoiceOptions($customerId($get)))
                        ->required(),
                    GhsInput::make('amount')->label('Amount')->required(),
                ])
                ->columns(2)
                ->minItems(1)
                ->visible(fn (Get $get): bool => $get('allocation_mode') === self::MODE_CHOOSE)
                ->columnSpanFull(),
        ];
    }

    /**
     * Form data (amounts already dehydrated to pesewas) -> RecordPayment input.
     *
     * @return array<string, mixed>
     */
    public static function toActionData(array $data, int $customerId): array
    {
        $mode = $data['allocation_mode'] ?? self::MODE_OLDEST;

        return [
            'customer_id' => $customerId,
            'amount' => (int) $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'auto_allocate' => $mode === self::MODE_OLDEST,
            'allocations' => $mode === self::MODE_CHOOSE
                ? array_values(array_map(fn (array $row): array => [
                    'sale_id' => (int) $row['sale_id'],
                    'amount' => (int) $row['amount'],
                ], $data['allocations'] ?? []))
                : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function openInvoiceOptions(?int $customerId): array
    {
        if ($customerId === null) {
            return [];
        }

        return Sale::query()
            ->where('customer_id', $customerId)
            ->where('status', SaleStatus::Confirmed)
            ->where('balance_due', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Sale $sale): array => [
                $sale->id => "{$sale->invoice_no} | due {$sale->due_date?->format('d M Y')} | balance ".Money::formatGhsGrouped($sale->balance_due),
            ])
            ->all();
    }

    public static function balancesLine(mixed $customerId): ?string
    {
        $customer = ($id = self::intOrNull($customerId)) !== null ? Customer::query()->find($id) : null;

        return $customer === null ? null : sprintf(
            'Owes %s | credit %s',
            Money::formatGhsGrouped($customer->outstanding_balance),
            Money::formatGhsGrouped($customer->credit_balance),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
