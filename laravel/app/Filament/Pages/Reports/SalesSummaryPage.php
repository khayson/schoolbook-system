<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\SalesSummaryReport;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Level;
use App\Models\Subject;
use Filament\Forms\Components\Select;

/** Revenue, order discounts and collections by day, week or month, with filters. */
class SalesSummaryPage extends ReportPage
{
    protected static ?string $slug = 'reports/sales-summary';

    protected static ?string $navigationLabel = 'Sales summary';

    protected static ?string $title = 'Sales summary';

    protected static ?int $navigationSort = 1;

    protected function defaultFilters(): array
    {
        return [...self::thisMonth(), 'group_by' => 'day'];
    }

    protected function filterFields(): array
    {
        return [
            self::dateField('from', 'From'),
            self::dateField('to', 'To'),
            Select::make('group_by')->label('Group by')->options(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'])->required()->selectablePlaceholder(false)->live(),
            Select::make('customer_id')->label('Customer')->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
            Select::make('level_id')->label('Level')->options(fn () => Level::query()->orderBy('sort_order')->pluck('name', 'id'))->live(),
            Select::make('subject_id')->label('Subject')->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id'))->live(),
            Select::make('language_id')->label('Language')->options(fn () => Language::query()->orderBy('name')->pluck('name', 'id'))->live(),
        ];
    }

    public function report(): array
    {
        $filters = array_filter([
            'customer_id' => $this->filter('customer_id'),
            'level_id' => $this->filter('level_id'),
            'subject_id' => $this->filter('subject_id'),
            'language_id' => $this->filter('language_id'),
        ], fn ($v) => $v !== null);
        $r = app(SalesSummaryReport::class)->run($this->filter('from'), $this->filter('to'), $this->filter('group_by', 'day'), array_map('intval', $filters));
        $catalog = array_intersect_key($filters, array_flip(['level_id', 'subject_id', 'language_id'])) !== [];

        return [
            'columns' => [
                'period' => ['Period', 'text'],
                'sales_count' => ['Sales', 'int'],
                'gross' => ['Gross', 'money'],
                'order_discounts' => ['Order discounts', 'money'],
                'revenue' => ['Revenue', 'money'],
                'collections' => ['Collections', 'money'],
            ],
            'rows' => $r['rows'],
            'totals' => $r['totals'],
            'note' => $catalog ? 'Filtered by level, subject or language: line revenue only; order discounts and collections cannot be split by book.' : null,
        ];
    }
}
