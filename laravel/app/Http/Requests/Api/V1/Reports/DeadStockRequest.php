<?php

namespace App\Http\Requests\Api\V1\Reports;

/** GET /reports/dead-stock. */
class DeadStockRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
