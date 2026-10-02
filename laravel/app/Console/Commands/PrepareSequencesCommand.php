<?php

namespace App\Console\Commands;

use App\Services\NumberSequenceService;
use Illuminate\Console\Command;

/**
 * Creates next year's counter rows ahead of time (spec 5.18), so no live request has to
 * create one. Idempotent. Scheduled for 15 December.
 */
class PrepareSequencesCommand extends Command
{
    /**
     * Year-scoped sequences: invoices, receipts, goods receipts.
     */
    public const YEARLY_KEYS = ['inv', 'rct', 'grn'];

    protected $signature = 'sequences:prepare {--year= : Year to prepare (default: next year)}';

    protected $description = 'Create invoice, receipt and goods-receipt number sequences for a year in advance';

    public function handle(NumberSequenceService $sequences): int
    {
        $year = $this->option('year') !== null ? (int) $this->option('year') : (int) now()->addYear()->format('Y');

        if ($year < 2000 || $year > 9999) {
            $this->error("Invalid year [{$year}].");

            return self::FAILURE;
        }

        foreach (self::YEARLY_KEYS as $key) {
            $sequences->prepare($key, $year);
        }

        $this->info('Prepared '.implode(', ', self::YEARLY_KEYS)." sequences for {$year}.");

        return self::SUCCESS;
    }
}
