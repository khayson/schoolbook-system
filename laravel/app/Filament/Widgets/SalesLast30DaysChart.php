<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\SalesSummaryReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/** Revenue per day over the last 30 days (today included), in GHS. */
class SalesLast30DaysChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Sales, last 30 days (GHS)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return Gate::allows('view-reports');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = self::rows();

        return [
            'datasets' => [[
                'label' => 'Revenue',
                // Display only: chart.js needs numbers; the figures stay integer pesewas elsewhere.
                'data' => array_map(fn (array $r) => $r['revenue'] / 100, $rows),
            ]],
            'labels' => array_map(fn (array $r) => Carbon::parse($r['period'])->format('d M'), $rows),
        ];
    }

    /** @return list<array{period: string, revenue: int}> */
    public static function rows(): array
    {
        $to = now()->toDateString();
        $from = now()->subDays(29)->toDateString();

        return array_map(
            fn (array $r) => ['period' => $r['period'], 'revenue' => $r['revenue']],
            app(SalesSummaryReport::class)->run($from, $to, 'day')['rows'],
        );
    }
}
