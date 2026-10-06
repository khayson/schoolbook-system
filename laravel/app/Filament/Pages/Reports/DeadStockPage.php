<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\DeadStockReport;
use App\Services\Money;
use Filament\Forms\Components\TextInput;

/** Products with stock and no confirmed sale in the last N days. */
class DeadStockPage extends ReportPage
{
    protected static ?string $slug = 'reports/dead-stock';

    protected static ?string $navigationLabel = 'Dead stock';

    protected static ?string $title = 'Dead stock';

    protected static ?int $navigationSort = 6;

    protected function defaultFilters(): array
    {
        return ['as_of' => now()->toDateString(), 'days' => 90];
    }

    protected function filterFields(): array
    {
        return [
            self::dateField('as_of', 'As of'),
            TextInput::make('days')->label('No sale for (days)')->numeric()->minValue(1)->maxValue(3650)->required()->live(onBlur: true),
        ];
    }

    public function report(): array
    {
        $r = app(DeadStockReport::class)->run($this->filter('as_of'), max(1, min(3650, (int) $this->filter('days', 90))));

        return [
            'columns' => [
                'sku' => ['SKU', 'text'],
                'title' => ['Title', 'text'],
                'stock_on_hand' => ['On hand', 'int'],
                'last_sold_at' => ['Last sold', 'text'],
                'days_since_sale' => ['Days since', 'int'],
                'value_at_cost' => ['At cost', 'money'],
            ],
            'rows' => array_map(fn (array $row) => [...$row, 'last_sold_at' => $row['last_sold_at'] ?? 'Never'], $r['rows']),
            'totals' => ['value_at_cost' => $r['totals']['value_at_cost']],
            'summary' => ['Products' => (string) $r['totals']['count'], 'Cut-off' => $r['cutoff'], 'At cost' => Money::formatGhsGrouped($r['totals']['value_at_cost'])],
            'note' => "Today's stock; no confirmed sale since {$r['cutoff']}.",
        ];
    }
}
