<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\ReceivablesAgingReport;

/** Unpaid confirmed invoices by days past due, per customer. */
class ReceivablesAgingPage extends ReportPage
{
    protected static ?string $slug = 'reports/receivables-aging';

    protected static ?string $navigationLabel = 'Receivables aging';

    protected static ?string $title = 'Receivables aging';

    protected static ?int $navigationSort = 7;

    protected function defaultFilters(): array
    {
        return ['as_of' => now()->toDateString()];
    }

    protected function filterFields(): array
    {
        return [self::dateField('as_of', 'Buckets as of')];
    }

    public function report(): array
    {
        $r = app(ReceivablesAgingReport::class)->run($this->filter('as_of'));

        return [
            'columns' => [
                'name' => ['Customer', 'text'],
                'not_yet_due' => ['Not yet due', 'money'],
                'days_1_30' => ['1-30 days', 'money'],
                'days_31_60' => ['31-60 days', 'money'],
                'days_61_90' => ['61-90 days', 'money'],
                'days_90_plus' => ['Over 90 days', 'money'],
                'total' => ['Total', 'money'],
                'brought_forward' => ['Of which brought forward', 'money'],
            ],
            'rows' => $r['rows'],
            'totals' => $r['totals'],
            'note' => "Buckets as of {$r['as_of']}. Balances are today's: the date only moves invoices between buckets; it is not a historical report. \"Brought forward\" is the part owed from before the system (opening balances).",
        ];
    }
}
