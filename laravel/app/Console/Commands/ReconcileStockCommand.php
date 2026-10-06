<?php

namespace App\Console\Commands;

use App\Actions\Inventory\ReconcileStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Checks stock_on_hand against the movement ledger and the balance_after chain.
 * Report-only by default; exits non-zero while anything is off. --fix repairs
 * stock_on_hand only (never movements), then re-checks.
 */
class ReconcileStockCommand extends Command
{
    protected $signature = 'stock:reconcile
        {--fix : Set stock_on_hand to the sum of movements for mismatched products}
        {--product=* : Limit to these product ids}';

    protected $description = 'Verify stock_on_hand and the balance_after chain against the stock movements';

    public function handle(ReconcileStock $reconcile): int
    {
        $productIds = $this->productFilter();
        $problems = $reconcile->check($productIds);

        if ($this->clean($problems)) {
            $this->info('Stock matches the movements.');

            return self::SUCCESS;
        }

        $this->report($problems);

        if (! $this->option('fix')) {
            $this->warn($this->count($problems).' problem(s). --fix repairs stock_on_hand; a broken balance_after chain needs investigation.');

            return self::FAILURE;
        }

        $changed = $reconcile->repairStockOnHand(array_column($problems['stock'], 'product_id'));
        $this->info("Repaired stock_on_hand on {$changed} product(s).");

        $remaining = $reconcile->check($productIds);
        if (! $this->clean($remaining)) {
            $this->error($this->count($remaining).' problem(s) remain; movements are never repaired automatically:');
            $this->report($remaining);

            return self::FAILURE;
        }

        $this->info('Stock matches the movements.');

        return self::SUCCESS;
    }

    /** Scheduler onFailure hook: re-checks so the log carries the current counts. */
    public static function logScheduledFailure(): void
    {
        $problems = app(ReconcileStock::class)->check();

        Log::critical('stock:reconcile found stock problems', [
            'stock_mismatches' => count($problems['stock']),
            'chain_breaks' => count($problems['chain']),
            'products' => array_values(array_unique([
                ...array_column($problems['stock'], 'product_id'),
                ...array_column($problems['chain'], 'product_id'),
            ])),
        ]);
    }

    private function clean(array $problems): bool
    {
        return $problems['stock'] === [] && $problems['chain'] === [];
    }

    private function count(array $problems): int
    {
        return count($problems['stock']) + count($problems['chain']);
    }

    private function report(array $problems): void
    {
        if ($problems['stock'] !== []) {
            $this->line('stock_on_hand differs from the sum of movements:');
            $this->table(['Product', 'SKU', 'stock_on_hand', 'Sum of movements'], array_map('array_values', $problems['stock']));
        }
        if ($problems['chain'] !== []) {
            $this->line('balance_after does not follow from the previous movement:');
            $this->table(['Movement', 'Product', 'Quantity', 'balance_after', 'Expected'], array_map('array_values', $problems['chain']));
        }
    }

    /** @return list<int>|null */
    private function productFilter(): ?array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('product'))));

        return $ids === [] ? null : $ids;
    }
}
