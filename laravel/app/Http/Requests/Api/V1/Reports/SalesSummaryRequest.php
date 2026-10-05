<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Validation\Rule;

/** GET /reports/sales-summary. Daily grouping is limited to a year. */
class SalesSummaryRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...$this->rangeRules(),
            'group_by' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('levels', 'id')],
            'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')],
            'language_id' => ['sometimes', 'nullable', 'integer', Rule::exists('languages', 'id')],
            ...($this->input('group_by', 'day') === 'day' ? ['to' => [...$this->rangeRules()['to'], $this->maxRange(367)]] : []),
        ];
    }
}
