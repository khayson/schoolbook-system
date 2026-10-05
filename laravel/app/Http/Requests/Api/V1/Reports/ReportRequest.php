<?php

namespace App\Http\Requests\Api\V1\Reports;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Base for report requests: owner only (gate view-reports), dates as YYYY-MM-DD in
 * Africa/Accra. Ranges are inclusive and at most two years.
 */
abstract class ReportRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 731;

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('view-reports');
    }

    /** @return array<string, list<mixed>> */
    protected function rangeRules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', $this->maxRange()],
        ];
    }

    protected function maxRange(int $days = self::MAX_RANGE_DAYS): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($days): void {
            $from = $this->input('from');
            if (is_string($from) && is_string($value) && strtotime($from) && strtotime($value)
                && Carbon::parse($from)->diffInDays(Carbon::parse($value)) >= $days) {
                $fail("The period can be at most {$days} days.");
            }
        };
    }

    /** A date parameter, or today in the app timezone. */
    public function dateOrToday(string $key): string
    {
        return (string) ($this->validated($key) ?? now()->toDateString());
    }
}
