<?php

namespace App\Actions\Payments;

use App\Exceptions\PaymentAlreadyVoidException;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Receipt PDF for a valid payment. Shared by the API and (2D) Filament.
 * Void payments have no receipt: 409 payment_already_void.
 */
class RenderReceiptPdf
{
    public function execute(Payment $payment): DomPdf
    {
        if (! $payment->isValid()) {
            throw new PaymentAlreadyVoidException($payment, 'receipt');
        }

        $payment->loadMissing(['customer', 'receivedBy']);

        // Net applied per invoice (originals minus any reversals), with each invoice's
        // balance as it stands now.
        $lines = PaymentAllocation::query()
            ->with('sale')
            ->where('payment_id', $payment->id)
            ->get()
            ->groupBy('sale_id')
            ->map(fn ($rows) => [
                'invoice_no' => $rows->first()->sale->invoice_no,
                'amount' => (int) $rows->sum('amount'),
                'balance_due' => (int) $rows->first()->sale->balance_due,
            ])
            ->filter(fn (array $line): bool => $line['amount'] !== 0)
            ->sortBy('invoice_no')
            ->values();

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'lines' => $lines,
            'business' => [
                'name' => (string) Setting::getValue('business_name', ''),
                'address' => (string) Setting::getValue('business_address', ''),
                'phone' => (string) Setting::getValue('business_phone', ''),
                'footer' => (string) Setting::getValue('invoice_footer', ''),
            ],
        ])->setPaper('a5');
    }

    public function filename(Payment $payment): string
    {
        return $payment->receipt_no.'.pdf';
    }
}
