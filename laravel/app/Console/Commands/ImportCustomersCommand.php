<?php

namespace App\Console\Commands;

use App\Actions\Customers\ImportCustomersFromCsv;
use App\Models\User;
use App\Services\Money;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * customers:import file.csv: a dry run by default (prints what would happen and the total
 * of opening balances, writes nothing); --commit imports. Template:
 * docs/templates/customers-import-template.csv.
 */
class ImportCustomersCommand extends Command
{
    protected $signature = 'customers:import
        {file : CSV file (docs/templates/customers-import-template.csv)}
        {--commit : Write the customers and opening balances (default: dry run)}
        {--owner= : Email of the owner recorded as having entered the opening balances (default: the only owner)}';

    protected $description = 'Import customers and opening balances from a CSV file (dry run unless --commit)';

    public function handle(ImportCustomersFromCsv $import): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }
        $csv = (string) file_get_contents($path);
        $commit = (bool) $this->option('commit');

        try {
            if ($commit) {
                $owner = $this->owner();
                $result = $import->commit($owner, $csv);
                $plan = $result['plan'];
            } else {
                $plan = $import->plan($csv);
            }
        } catch (InvalidArgumentException $e) {
            // A refused commit still shows the rows, so the owner sees what to fix.
            if ($commit) {
                try {
                    $this->report($import->plan($csv));
                } catch (InvalidArgumentException) {
                    // the file itself is unreadable: the message below says why
                }
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($plan);
        if ($commit) {
            $this->info("Imported {$plan['create']} customer(s): ".($result['created'] === [] ? 'none' : implode(', ', $result['created'])).'.');
        } else {
            $this->warn('Dry run: nothing was written. Check the total against the paper ledger, then run again with --commit.');
        }

        return $plan['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function report(array $plan): void
    {
        $this->table(['Row', 'Name', 'Result', 'Detail'], array_map(fn (array $r) => [$r['row'], $r['name'], $r['action'], $r['detail']], $plan['rows']));
        $this->line("To create: {$plan['create']}   Skipped: {$plan['skip']}   Errors: {$plan['errors']}");
        $this->line('Total opening balances to create: '.Money::formatGhsGrouped($plan['opening_balance_total']));
    }

    private function owner(): User
    {
        $email = $this->option('owner');
        $owners = User::query()->where('role', 'owner')->where('is_active', true)
            ->when($email, fn ($q) => $q->where('email', $email))->get();
        if ($owners->count() !== 1) {
            throw new InvalidArgumentException($email ? "No active owner with email {$email}." : 'More than one owner: pass --owner=email.');
        }

        return $owners->first();
    }
}
