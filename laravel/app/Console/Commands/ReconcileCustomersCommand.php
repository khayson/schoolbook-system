<?php

namespace App\Console\Commands;

use App\DTOs\Ledger\InvariantViolation;
use App\Services\MoneyInvariants;
use Illuminate\Console\Command;

/**
 * Checks the money invariants (spec 9.5). Report-only by default; exits non-zero when
 * anything is off so the scheduler/monitoring notices. --fix rebuilds the cached
 * balances of the affected customers from the allocation ledger, then re-checks.
 */
class ReconcileCustomersCommand extends Command
{
    protected $signature = 'customers:reconcile
        {--fix : Rebuild cached balances from the allocation ledger for affected customers}
        {--customer=* : Limit to these customer ids}';

    protected $description = 'Verify payment/sale/customer money invariants against the allocation ledger';

    public function handle(MoneyInvariants $invariants): int
    {
        $customerIds = $this->customerFilter();
        $violations = $invariants->check($customerIds);

        if ($violations === []) {
            $this->info('All money invariants hold.');

            return self::SUCCESS;
        }

        $this->report($violations);

        if (! $this->option('fix')) {
            $this->warn(count($violations).' violation(s). Run with --fix to rebuild cached balances.');

            return self::FAILURE;
        }

        $affected = collect($violations)->pluck('customerId')->filter()->unique()->sort()->values();
        $changed = 0;
        foreach ($affected as $customerId) {
            $changed += $invariants->repair($customerId);
        }
        $this->info("Repaired {$changed} row(s) across {$affected->count()} customer(s).");

        $remaining = $invariants->check($customerIds);
        if ($remaining !== []) {
            $this->error(count($remaining).' violation(s) remain; these need manual investigation (ledger rows are never auto-repaired):');
            $this->report($remaining);

            return self::FAILURE;
        }

        $this->info('All money invariants hold.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    private function customerFilter(): ?array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('customer'))));

        return $ids === [] ? null : $ids;
    }

    /**
     * @param  list<InvariantViolation>  $violations
     */
    private function report(array $violations): void
    {
        $this->table(
            ['Invariant', 'Subject', 'Customer', 'Field', 'Expected', 'Actual'],
            array_map(fn (InvariantViolation $v): array => array_values($v->toArray()), $violations),
        );
    }
}
