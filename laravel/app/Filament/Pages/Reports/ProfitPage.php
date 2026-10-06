<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\ProfitReport;
use App\Services\Money;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/** Gross profit by product, level, subject, language or period; order-level discounts as one separate line. */
class ProfitPage extends ReportPage
{
    protected static ?string $slug = 'reports/profit';

    protected static ?string $navigationLabel = 'Profit';

    protected static ?string $title = 'Profit';

    protected static ?int $navigationSort = 2;

    protected function defaultFilters(): array
    {
        return [...self::thisMonth(), 'group_by' => 'product', 'period' => 'month'];
    }

    protected function filterFields(): array
    {
        return [
            self::dateField('from', 'From'),
            self::dateField('to', 'To'),
            Select::make('group_by')->label('Group by')->options(['product' => 'Product', 'level' => 'Level', 'subject' => 'Subject', 'language' => 'Language', 'period' => 'Period'])->required()->selectablePlaceholder(false)->live(),
            Select::make('period')->options(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'])->selectablePlaceholder(false)->live()
                ->visible(fn (Get $get): bool => $get('group_by') === 'period'),
        ];
    }

    public function report(): array
    {
        $r = app(ProfitReport::class)->run($this->filter('from'), $this->filter('to'), $this->filter('group_by', 'product'), $this->filter('period', 'month'));
        $t = $r['totals'];

        return [
            'columns' => [
                'label' => [$this->filter('group_by') === 'period' ? 'Period' : 'Name', 'text'],
                'quantity' => ['Quantity', 'int'],
                'revenue' => ['Revenue', 'money'],
                'cost' => ['Cost', 'money'],
                'gross_profit' => ['Gross profit', 'money'],
            ],
            'rows' => $r['rows'],
            'totals' => array_intersect_key($t, array_flip(['quantity', 'revenue', 'cost', 'gross_profit'])),
            'summary' => [
                'Gross profit' => Money::formatGhsGrouped($t['gross_profit']),
                'Order-level discounts' => Money::formatGhsGrouped(-$t['order_level_discounts']),
                'Net profit' => Money::formatGhsGrouped($t['net_profit']),
            ],
        ];
    }
}
