<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Validation\Rule;

/** GET /reports/best-sellers. */
class BestSellersRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...$this->rangeRules(),
            'by' => ['sometimes', Rule::in(['product', 'level', 'subject', 'language'])],
            'sort' => ['sometimes', Rule::in(['quantity', 'revenue'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
