<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\StockValuationReport;

/** Stock at cost and at selling price; negative stock is valued at 0 and counted separately. */
class StockValuationPage extends ReportPage
{
    protected static ?string $slug = 'reports/stock-valuation';

    protected static ?string $navigationLabel = 'Stock valuation';

    protected static ?string $title = 'Stock valuation';

    protected static ?int $navigationSort = 4;

    public function report(): array
    {
        $r = app(StockValuationReport::class)->run();
        $t = $r['totals'];

        return [
            'columns' => [
                'sku' => ['SKU', 'text'],
                'title' => ['Title', 'text'],
                'stock_on_hand' => ['On hand', 'int'],
                'counted_quantity' => ['Valued', 'int'],
                'cost_price' => ['Cost', 'money'],
                'selling_price' => ['Price', 'money'],
                'value_at_cost' => ['At cost', 'money'],
                'value_at_price' => ['At price', 'money'],
            ],
            'rows' => $r['rows'],
            'totals' => array_intersect_key($t, array_flip(['counted_quantity', 'value_at_cost', 'value_at_price'])),
            'summary' => [
                'Products with negative stock' => (string) $t['negative_stock_count'],
                'Negative units' => (string) $t['negative_stock_units'],
            ],
        ];
    }
}
