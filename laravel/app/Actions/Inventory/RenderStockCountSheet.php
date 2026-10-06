<?php

namespace App\Actions\Inventory;

use App\Actions\Customers\RenderStatementPdf;
use App\Models\StockCount;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Printable count sheet: the count's products by level, subject and title with a blank
 * column to write the counted quantity. The system quantity is deliberately not printed
 * (a blind count is not anchored to what the system expects).
 */
class RenderStockCountSheet
{
    public function execute(StockCount $count): DomPdf
    {
        $count->loadMissing('items.product.level', 'items.product.subject');

        $items = $count->items
            ->sortBy(fn ($i) => [$i->product?->level?->name, $i->product?->subject?->name, $i->product?->title])
            ->values();

        return Pdf::loadView('pdf.stock-count-sheet', [
            'count' => $count,
            'items' => $items,
            'business' => RenderStatementPdf::business(),
        ])->setPaper('a4');
    }

    public function filename(StockCount $count): string
    {
        return $count->reference.'-sheet.pdf';
    }
}
