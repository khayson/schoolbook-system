<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Validation\Rule;

/** GET /reports/profit. */
class ProfitRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...$this->rangeRules(),
            'group_by' => ['required', Rule::in(['product', 'level', 'subject', 'language', 'period'])],
            'period' => ['sometimes', Rule::in(['day', 'week', 'month'])],
        ];
    }
}
