<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Support\SaleLines;

/**
 * Best sellers by quantity or by revenue (line totals), per product, level, subject or
 * language, for confirmed sales in the period (docs/acceptance-phase3.md 3.3).
 */
class BestSellersReport
{
    public const SORTS = ['quantity', 'revenue'];

    /**
     * @return array{from: string, to: string, by: string, sort: string, rows: list<array>}
     */
    public function run(string $from, string $to, string $by, string $sort = 'quantity', int $limit = 10): array
    {
        $other = $sort === 'quantity' ? 'revenue' : 'quantity';
        $rows = SaleLines::byDimension($from, $to, $by);
        usort($rows, fn ($a, $b) => [$b[$sort], $b[$other], $a['label']] <=> [$a[$sort], $a[$other], $b['label']]);
        $rows = array_map(
            fn (array $r) => array_intersect_key($r, array_flip(['key', 'label', 'sku', 'quantity', 'revenue'])),
            array_slice($rows, 0, $limit),
        );

        return ['from' => $from, 'to' => $to, 'by' => $by, 'sort' => $sort, 'rows' => $rows];
    }
}
