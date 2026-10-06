<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\BestSellersReport;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/** Quantity and line revenue by product, level, subject or language. */
class BestSellersPage extends ReportPage
{
    protected static ?string $slug = 'reports/best-sellers';

    protected static ?string $navigationLabel = 'Best sellers';

    protected static ?string $title = 'Best sellers';

    protected static ?int $navigationSort = 3;

    protected function defaultFilters(): array
    {
        return [...self::thisMonth(), 'by' => 'product', 'sort' => 'quantity', 'limit' => 10];
    }

    protected function filterFields(): array
    {
        return [
            self::dateField('from', 'From'),
            self::dateField('to', 'To'),
            Select::make('by')->label('By')->options(['product' => 'Product', 'level' => 'Level', 'subject' => 'Subject', 'language' => 'Language'])->required()->selectablePlaceholder(false)->live(),
            Select::make('sort')->label('Sort by')->options(['quantity' => 'Quantity', 'revenue' => 'Revenue'])->required()->selectablePlaceholder(false)->live(),
            TextInput::make('limit')->numeric()->minValue(1)->maxValue(100)->live(onBlur: true),
        ];
    }

    public function report(): array
    {
        $limit = max(1, min(100, (int) $this->filter('limit', 10)));
        $r = app(BestSellersReport::class)->run($this->filter('from'), $this->filter('to'), $this->filter('by', 'product'), $this->filter('sort', 'quantity'), $limit);

        return [
            'columns' => [
                ...($this->filter('by') === 'product' ? ['sku' => ['SKU', 'text']] : []),
                'label' => ['Name', 'text'],
                'quantity' => ['Quantity', 'int'],
                'revenue' => ['Revenue', 'money'],
            ],
            'rows' => $r['rows'],
        ];
    }
}
