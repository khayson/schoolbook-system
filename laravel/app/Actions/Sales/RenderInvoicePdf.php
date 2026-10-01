<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\SaleNotEditableException;
use App\Models\Sale;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Invoice PDF for a confirmed sale. Shared by the API and (2D) Filament.
 * Drafts, cancelled and void sales have no printable invoice: 409 sale_not_editable.
 */
class RenderInvoicePdf
{
    public function execute(Sale $sale): DomPdf
    {
        if ($sale->status !== SaleStatus::Confirmed) {
            throw new SaleNotEditableException($sale, 'invoice');
        }

        $sale->loadMissing(['customer', 'items']);

        return Pdf::loadView('pdf.invoice', [
            'sale' => $sale,
            'business' => [
                'name' => (string) Setting::getValue('business_name', ''),
                'address' => (string) Setting::getValue('business_address', ''),
                'phone' => (string) Setting::getValue('business_phone', ''),
                'footer' => (string) Setting::getValue('invoice_footer', ''),
            ],
        ])->setPaper('a4');
    }

    public function filename(Sale $sale): string
    {
        return $sale->invoice_no.'.pdf';
    }
}
