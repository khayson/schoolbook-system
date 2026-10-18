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
use App\Actions\Reports\Support\ReportExporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\AsOfRequest;
use App\Http\Requests\Api\V1\Reports\BestSellersRequest;
use App\Http\Requests\Api\V1\Reports\DeadStockRequest;
use App\Http\Requests\Api\V1\Reports\PlainReportRequest;
use App\Http\Requests\Api\V1\Reports\ProfitRequest;
use App\Http\Requests\Api\V1\Reports\ReportRequest;
use App\Http\Requests\Api\V1\Reports\SalesSummaryRequest;
use App\Http\Resources\ReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner reports (docs/api.md, Reports). Figures are computed by the Report Actions;
 * definitions in docs/build-spec.md section 14 and docs/acceptance-phase3.md.
 * Optional `?format=json|csv|pdf` (default json).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportExporter $exporter,
    ) {}

    public function dashboard(AsOfRequest $request, DashboardReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        return $this->export($request, $report->run($request->dateOrToday('date')), 'dashboard');
    }

    public function salesSummary(SalesSummaryRequest $request, SalesSummaryReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        $v = $request->validated();

        return $this->export($request, $report->run($v['from'], $v['to'], $v['group_by'] ?? 'day', array_intersect_key(
            $v,
            array_flip(['customer_id', 'level_id', 'subject_id', 'language_id']),
        )), 'sales-summary');
    }

    public function profit(ProfitRequest $request, ProfitReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        $v = $request->validated();

        return $this->export($request, $report->run($v['from'], $v['to'], $v['group_by'], $v['period'] ?? 'month'), 'profit');
    }

    public function bestSellers(BestSellersRequest $request, BestSellersReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        $v = $request->validated();

        return $this->export($request, $report->run($v['from'], $v['to'], $v['by'] ?? 'product', $v['sort'] ?? 'quantity', (int) ($v['limit'] ?? 10)), 'best-sellers');
    }

    public function stockValuation(PlainReportRequest $request, StockValuationReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        return $this->export($request, $report->run(), 'stock-valuation');
    }

    public function lowStock(PlainReportRequest $request, LowStockReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        return $this->export($request, $report->run(), 'low-stock');
    }

    public function deadStock(DeadStockRequest $request, DeadStockReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        return $this->export($request, $report->run($request->dateOrToday('as_of'), (int) ($request->validated('days') ?? 90)), 'dead-stock');
    }

    public function receivablesAging(AsOfRequest $request, ReceivablesAgingReport $report): ReportResource|JsonResponse|StreamedResponse|Response
    {
        return $this->export($request, $report->run($request->dateOrToday('as_of')), 'receivables-aging');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function export(ReportRequest $request, array $payload, string $basename): ReportResource|JsonResponse|StreamedResponse|Response
    {
        $format = $request->exportFormat();
        if ($format === 'json') {
            return new ReportResource($payload);
        }

        return $this->exporter->respond($payload, $format, $basename);
    }
}
