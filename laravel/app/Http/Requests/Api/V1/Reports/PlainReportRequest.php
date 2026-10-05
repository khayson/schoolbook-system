<?php

namespace App\Http\Requests\Api\V1\Reports;

/** Reports without parameters (stock valuation, low stock): authorization only. */
class PlainReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [];
    }
}
