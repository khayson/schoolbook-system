<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\DashboardReport;
use App\Filament\Pages\Reports\LowStockPage;
use App\Filament\Pages\Reports\ReceivablesAgingPage;
use App\Filament\Pages\Reports\SalesSummaryPage;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Services\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/** Today's figures from DashboardReport (docs/acceptance-phase3.md 3.8). */
class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Today';

    public static function canView(): bool
    {
        return Gate::allows('view-reports');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $d = app(DashboardReport::class)->run(now()->toDateString());
        $sales = SalesSummaryPage::getUrl();

        return [
            Stat::make('Sales today', Money::formatGhsGrouped($d['sales_today']['revenue']))
                ->description($d['sales_today']['count'].' '.str('sale')->plural($d['sales_today']['count']))
                ->url($sales),
            Stat::make('Sales this month', Money::formatGhsGrouped($d['sales_month']['revenue']))
                ->description($d['sales_month']['count'].' '.str('sale')->plural($d['sales_month']['count']))
                ->url($sales),
            Stat::make('Collections today', Money::formatGhsGrouped($d['collections_today']))
                ->description('This month '.Money::formatGhsGrouped($d['collections_month']))
                ->url(PaymentResource::getUrl()),
            Stat::make('Owed to you', Money::formatGhsGrouped($d['owed']))
                ->description('Overdue '.Money::formatGhsGrouped($d['overdue']))
                ->color($d['overdue'] > 0 ? 'danger' : 'gray')
                ->url(ReceivablesAgingPage::getUrl()),
            Stat::make('Customer credit held', Money::formatGhsGrouped($d['credit']))
                ->url(CustomerResource::getUrl()),
            Stat::make('Low stock', (string) $d['low_stock_count'])
                ->description('products at or below reorder level')
                ->color($d['low_stock_count'] > 0 ? 'warning' : 'gray')
                ->url(LowStockPage::getUrl()),
        ];
    }
}
