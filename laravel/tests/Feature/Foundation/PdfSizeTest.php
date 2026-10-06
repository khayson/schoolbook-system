<?php

use App\Actions\Customers\RenderStatementPdf;
use App\Actions\Inventory\CreateStockCount;
use App\Actions\Inventory\RenderStockCountSheet;
use App\Actions\Payments\RenderReceiptPdf;
use App\Actions\Sales\RenderInvoicePdf;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

/*
 * The four PDFs are shared over WhatsApp: with DomPDF font subsetting the embedded
 * DejaVu Sans carries only the glyphs used, not the whole font (~880 KB before, 3.4
 * review). Sizes are on the reports dataset.
 */

test('invoice, receipt, statement and count sheet PDFs stay small', function () {
    $this->seed(DatabaseSeeder::class);
    $owner = User::factory()->owner()->create();
    $fx = ReportsFixture::build($owner);

    $pdfs = [
        'invoice (sale 6, 2 lines)' => app(RenderInvoicePdf::class)->execute($fx->sales[6])->output(),
        'receipt (P3)' => app(RenderReceiptPdf::class)->execute($fx->payments['P3'])->output(),
        'statement (Alpha, June, 8 lines)' => app(RenderStatementPdf::class)->execute($fx->customers['alpha'], '2026-06-01', '2026-06-30')->output(),
        'count sheet (6 products)' => app(RenderStockCountSheet::class)->execute(app(CreateStockCount::class)->execute($owner, []))->output(),
    ];

    foreach ($pdfs as $name => $bytes) {
        fwrite(STDERR, sprintf("%-34s %7.1f KB\n", $name, strlen($bytes) / 1024));
        expect(substr($bytes, 0, 4))->toBe('%PDF')
            ->and(strlen($bytes))->toBeLessThan(40 * 1024, "{$name} is ".strlen($bytes).' bytes');
    }
});
