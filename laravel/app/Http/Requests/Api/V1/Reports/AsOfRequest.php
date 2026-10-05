<?php

namespace App\Http\Requests\Api\V1\Reports;

/** GET /reports/receivables-aging (as_of), GET /reports/dashboard (date); both default to today. */
class AsOfRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
