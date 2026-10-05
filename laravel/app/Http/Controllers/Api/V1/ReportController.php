<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reports\BestSellersReport;
use App\Actions\Reports\DashboardReport;
use App\Actions\Reports\DeadStockReport;
use App\Actions\Reports\LowStockReport;
use App\Actions\Reports\ProfitReport;
use App\Actions\Reports\ReceivablesAgingReport;
use App\Actions\Reports\SalesSummaryReport;
use App\Actions\Reports\StockValuationReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\AsOfRequest;
use App\Http\Requests\Api\V1\Reports\BestSellersRequest;
use App\Http\Requests\Api\V1\Reports\DeadStockRequest;
use App\Http\Requests\Api\V1\Reports\PlainReportRequest;
use App\Http\Requests\Api\V1\Reports\ProfitRequest;
use App\Http\Requests\Api\V1\Reports\SalesSummaryRequest;
use App\Http\Resources\ReportResource;

/**
 * Owner reports (docs/api.md, Reports). Figures are computed by the Report Actions;
 * definitions in docs/build-spec.md section 14 and docs/acceptance-phase3.md.
 */
class ReportController extends Controller
{
    public function dashboard(AsOfRequest $request, DashboardReport $report): ReportResource
    {
        return new ReportResource($report->run($request->dateOrToday('date')));
    }

    public function salesSummary(SalesSummaryRequest $request, SalesSummaryReport $report): ReportResource
    {
        $v = $request->validated();

        return new ReportResource($report->run($v['from'], $v['to'], $v['group_by'] ?? 'day', array_intersect_key(
            $v,
            array_flip(['customer_id', 'level_id', 'subject_id', 'language_id']),
        )));
    }

    public function profit(ProfitRequest $request, ProfitReport $report): ReportResource
    {
        $v = $request->validated();

        return new ReportResource($report->run($v['from'], $v['to'], $v['group_by'], $v['period'] ?? 'month'));
    }

    public function bestSellers(BestSellersRequest $request, BestSellersReport $report): ReportResource
    {
        $v = $request->validated();

        return new ReportResource($report->run($v['from'], $v['to'], $v['by'] ?? 'product', $v['sort'] ?? 'quantity', (int) ($v['limit'] ?? 10)));
    }

    public function stockValuation(PlainReportRequest $request, StockValuationReport $report): ReportResource
    {
        return new ReportResource($report->run());
    }

    public function lowStock(PlainReportRequest $request, LowStockReport $report): ReportResource
    {
        return new ReportResource($report->run());
    }

    public function deadStock(DeadStockRequest $request, DeadStockReport $report): ReportResource
    {
        return new ReportResource($report->run($request->dateOrToday('as_of'), (int) ($request->validated('days') ?? 90)));
    }

    public function receivablesAging(AsOfRequest $request, ReceivablesAgingReport $report): ReportResource
    {
        return new ReportResource($report->run($request->dateOrToday('as_of')));
    }
}
