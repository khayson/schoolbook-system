<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Statement PDF: the CustomerStatement figures with the business details. Shared by the
 * API and (3.4) Filament.
 */
class RenderStatementPdf
{
    public function __construct(private readonly CustomerStatement $statement) {}

    public function execute(Customer $customer, string $from, string $to): DomPdf
    {
        return Pdf::loadView('pdf.statement', [
            'statement' => $this->statement->run($customer, $from, $to),
            'business' => self::business(),
        ])->setPaper('a4');
    }

    public function filename(Customer $customer, string $from, string $to): string
    {
        return "statement-{$customer->code}-{$from}-{$to}.pdf";
    }

    /** @return array{name: string, address: string, phone: string, footer: string} */
    public static function business(): array
    {
        return [
            'name' => (string) Setting::getValue('business_name', ''),
            'address' => (string) Setting::getValue('business_address', ''),
            'phone' => (string) Setting::getValue('business_phone', ''),
            'footer' => (string) Setting::getValue('invoice_footer', ''),
        ];
    }
}
