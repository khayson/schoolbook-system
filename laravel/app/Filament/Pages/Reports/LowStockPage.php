<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\LowStockReport;

/** Active products at or below their reorder level, largest shortfall first. */
class LowStockPage extends ReportPage
{
    protected static ?string $slug = 'reports/low-stock';

    protected static ?string $navigationLabel = 'Low stock';

    protected static ?string $title = 'Low stock';

    protected static ?int $navigationSort = 5;

    public function report(): array
    {
        $r = app(LowStockReport::class)->run();

        return [
            'columns' => [
                'sku' => ['SKU', 'text'],
                'title' => ['Title', 'text'],
                'stock_on_hand' => ['On hand', 'int'],
                'reorder_level' => ['Reorder level', 'int'],
                'shortfall' => ['Shortfall', 'int'],
                'status' => ['Status', 'text'],
            ],
            'rows' => array_map(fn (array $row) => [...$row, 'status' => $row['status'] === 'out_of_stock' ? 'Out of stock' : 'Low'], $r['rows']),
            'summary' => ['Products' => (string) $r['count']],
        ];
    }
}
