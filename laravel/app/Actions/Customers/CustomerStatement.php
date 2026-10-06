<?php

namespace App\Actions\Customers;

use App\Actions\Reports\Support\ReportPeriods;
use App\Enums\PaymentRecordStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's statement for an inclusive date range (spec 9.4; docs/acceptance-phase3.md
 * 3.9). Positive = the customer owes. Events: invoice at confirmed_at (+total), invoice
 * void at voided_at (ÃƒÆ’Ã‚Â¢Ãƒâ€¹Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢total), payment at paid_at (ÃƒÆ’Ã‚Â¢Ãƒâ€¹Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢amount), payment void at voided_at
 * (+amount). Credit applications are not events. Opening balance = every event before
 * `from`; at the same timestamp invoices come first, then payments, then invoice voids,
 * then payment voids, then record id. For a period ending after the last event the
 * closing balance equals outstanding_balance ÃƒÆ’Ã‚Â¢Ãƒâ€¹Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ credit_balance.
 */
class CustomerStatement
{
    /** Same-timestamp order. */
    public const ORDER = ['invoice' => 1, 'payment' => 2, 'invoice_void' => 3, 'payment_void' => 4];

    private const METHODS = ['cash' => 'cash', 'momo' => 'mobile money', 'bank_transfer' => 'bank transfer', 'cheque' => 'cheque'];

    /**
     * @return array{customer: array, from: string, to: string, opening_balance: int, lines: list<array>, totals: array{debits: int, credits: int}, closing_balance: int}
     */
    public function run(Customer $customer, string $from, string $to): array
    {
        // Full datetimes, so an event at exactly 00:00 compares the same on SQLite (text)
        // as on MySQL: it belongs to its day, not to the opening balance.
        [$start, $end] = array_map(fn (string $d) => $d.' 00:00:00', ReportPeriods::bounds($from, $to));

        $opening = (int) DB::query()->fromSub($this->events($customer->id), 'e')
            ->where('at', '<', $start)
            ->sum('amount');

        $rows = DB::query()->fromSub($this->events($customer->id), 'e')
            ->where('at', '>=', $start)
            ->where('at', '<', $end)
            ->orderBy('at')
            ->orderBy('kind_order')
            ->orderBy('source_id')
            ->get();

        $balance = $opening;
        $debits = 0;
        $credits = 0;
        $lines = [];
        foreach ($rows as $row) {
            $amount = (int) $row->amount;
            $balance += $amount;
            $amount > 0 ? $debits += $amount : $credits -= $amount;
            $at = Carbon::parse($row->at);
            $isSale = in_array($row->type, ['invoice', 'invoice_void'], true);

            $lines[] = [
                'at' => $at->toIso8601String(),
                'date' => $at->toDateString(),
                'type' => $row->type,
                'sale_id' => $isSale ? (int) $row->source_id : null,
                'payment_id' => $isSale ? null : (int) $row->source_id,
                'reference' => $row->reference,
                'description' => $this->describe($row),
                'debit' => max(0, $amount),
                'credit' => max(0, -$amount),
                'amount' => $amount,
                'balance' => $balance,
            ];
        }

        return [
            'customer' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'from' => Carbon::parse($from)->toDateString(),
            'to' => Carbon::parse($to)->toDateString(),
            'opening_balance' => $opening,
            'lines' => $lines,
            'totals' => ['debits' => $debits, 'credits' => $credits],
            'closing_balance' => $opening + $debits - $credits,
        ];
    }

    /**
     * Every statement event of the customer, one row each: at, kind_order, type,
     * source_id, reference, note, amount (signed). Amounts are cast to signed before
     * negating (the money columns are unsigned on MySQL).
     */
    private function events(int $customerId): Builder
    {
        $confirmedOrVoid = [SaleStatus::Confirmed->value, SaleStatus::Void->value];

        $invoices = DB::table('sales')
            ->where('customer_id', $customerId)
            ->whereIn('status', $confirmedOrVoid)
            ->whereNotNull('confirmed_at')
            ->selectRaw("confirmed_at as at, 1 as kind_order, 'invoice' as type, id as source_id, invoice_no as reference, NULL as note, CAST(total AS SIGNED) as amount");

        $invoiceVoids = DB::table('sales')
            ->where('customer_id', $customerId)
            ->where('status', SaleStatus::Void->value)
            ->whereNotNull('confirmed_at')
            ->whereNotNull('voided_at')
            ->selectRaw("voided_at as at, 3 as kind_order, 'invoice_void' as type, id as source_id, invoice_no as reference, void_reason as note, -CAST(total AS SIGNED) as amount");

        $payments = DB::table('payments')
            ->where('customer_id', $customerId)
            ->selectRaw("paid_at as at, 2 as kind_order, 'payment' as type, id as source_id, receipt_no as reference, method as note, -CAST(amount AS SIGNED) as amount");

        $paymentVoids = DB::table('payments')
            ->where('customer_id', $customerId)
            ->where('status', PaymentRecordStatus::Void->value)
            ->whereNotNull('voided_at')
            ->selectRaw("voided_at as at, 4 as kind_order, 'payment_void' as type, id as source_id, receipt_no as reference, void_reason as note, CAST(amount AS SIGNED) as amount");

        return $invoices->unionAll($invoiceVoids)->unionAll($payments)->unionAll($paymentVoids);
    }

    private function describe(object $row): string
    {
        $note = trim((string) $row->note);

        return match ($row->type) {
            'invoice' => "Invoice {$row->reference}",
            'payment' => "Payment {$row->reference}".($note !== '' ? ' ('.(self::METHODS[$note] ?? $note).')' : ''),
            'invoice_void' => "Void of invoice {$row->reference}".($note !== '' ? ": {$note}" : ''),
            'payment_void' => "Void of payment {$row->reference}".($note !== '' ? ": {$note}" : ''),
        };
    }
}
