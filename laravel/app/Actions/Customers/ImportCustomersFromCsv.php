<?php

namespace App\Actions\Customers;

use App\Actions\Sales\CreateOpeningBalance;
use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Models\Customer;
use App\Models\User;
use App\Services\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Customers (and their opening balances) from the paper ledger, as a CSV file
 * (docs/templates/customers-import-template.csv; docs/acceptance-phase3.md 6.2).
 *
 * plan() writes nothing: for every row it says create / skip (already a customer, or the
 * same school earlier in the file) / error (and why), and totals the opening balances to
 * be created so the owner can compare them with the paper ledger. commit() refuses while
 * any row has an error, then creates everything in one transaction. Re-running a file
 * creates nothing new: rows match existing customers by code, else by name + phone.
 */
class ImportCustomersFromCsv
{
    public const COLUMNS = ['code', 'name', 'type', 'region', 'district', 'address', 'contact_person', 'phone', 'email',
        'credit_limit', 'opening_balance', 'opening_balance_date', 'opening_balance_due', 'notes'];

    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly CreateOpeningBalance $createOpeningBalance,
    ) {}

    /**
     * @return array{rows: list<array{row: int, name: string, action: string, detail: string, customer?: array, opening_balance?: array|null}>, create: int, skip: int, errors: int, opening_balance_total: int}
     */
    public function plan(string $csv): array
    {
        $rows = [];
        $seen = [];
        $existingByCode = Customer::withTrashed()->pluck('id', 'code')->all();
        $existingByKey = [];
        foreach (Customer::withTrashed()->get(['id', 'code', 'name', 'phone']) as $c) {
            $existingByKey[self::matchKey($c->name, $c->phone)] = $c->code;
        }

        foreach ($this->parse($csv) as $line => $raw) {
            $name = trim($raw['name'] ?? '');
            try {
                [$customer, $openingBalance] = $this->validate($raw);
            } catch (InvalidArgumentException $e) {
                $rows[] = ['row' => $line, 'name' => $name, 'action' => 'error', 'detail' => $e->getMessage()];

                continue;
            }

            $code = trim($raw['code'] ?? '');
            $key = self::matchKey($customer['name'], $customer['phone']);
            if ($code !== '' && isset($existingByCode[$code])) {
                $rows[] = ['row' => $line, 'name' => $name, 'action' => 'skip', 'detail' => "already a customer ({$code})"];
            } elseif (isset($existingByKey[$key])) {
                $rows[] = ['row' => $line, 'name' => $name, 'action' => 'skip', 'detail' => "already a customer ({$existingByKey[$key]}, same name and phone)"];
            } elseif (isset($seen[$key])) {
                $rows[] = ['row' => $line, 'name' => $name, 'action' => 'skip', 'detail' => "same school as row {$seen[$key]} in this file"];
            } else {
                $seen[$key] = $line;
                $rows[] = [
                    'row' => $line,
                    'name' => $name,
                    'action' => 'create',
                    'detail' => $openingBalance === null ? 'no opening balance' : 'opening balance '.Money::formatGhsGrouped($openingBalance['amount']).", due {$openingBalance['due_date']}",
                    'customer' => $customer,
                    'opening_balance' => $openingBalance,
                ];
            }
        }

        $count = fn (string $action) => count(array_filter($rows, fn ($r) => $r['action'] === $action));

        return [
            'rows' => $rows,
            'create' => $count('create'),
            'skip' => $count('skip'),
            'errors' => $count('error'),
            'opening_balance_total' => array_sum(array_map(fn ($r) => $r['opening_balance']['amount'] ?? 0, array_filter($rows, fn ($r) => $r['action'] === 'create'))),
        ];
    }

    /**
     * @return array{plan: array, created: list<string>} the plan, and the codes created
     */
    public function commit(User $user, string $csv): array
    {
        $plan = $this->plan($csv);
        if ($plan['errors'] > 0) {
            throw new InvalidArgumentException("Nothing imported: fix the {$plan['errors']} row(s) with errors first.");
        }

        $created = DB::transaction(function () use ($user, $plan) {
            $codes = [];
            foreach ($plan['rows'] as $row) {
                if ($row['action'] !== 'create') {
                    continue;
                }
                $customer = $this->createCustomer->execute($row['customer']);
                if ($row['opening_balance'] !== null) {
                    $this->createOpeningBalance->execute($user, $customer, $row['opening_balance']);
                }
                $codes[] = $customer->code;
            }

            return $codes;
        });

        return ['plan' => $plan, 'created' => $created];
    }

    /** Name (case and spacing ignored) + phone digits (+233 / 233 written as 0). */
    public static function matchKey(string $name, ?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '233')) {
            $digits = '0'.substr($digits, 3);
        }

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name))).'|'.$digits;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{amount: int, date: string, due_date: string}|null}
     */
    private function validate(array $raw): array
    {
        $v = fn (string $k): string => trim((string) ($raw[$k] ?? ''));

        if ($v('name') === '') {
            throw new InvalidArgumentException('name is required');
        }
        $type = strtolower($v('type')) ?: CustomerType::School->value;
        if (CustomerType::tryFrom($type) === null) {
            throw new InvalidArgumentException("type must be school, reseller or individual (got \"{$v('type')}\")");
        }
        if ($v('region') === '') {
            throw new InvalidArgumentException('region is required');
        }
        $region = collect(GhanaRegion::cases())->first(fn (GhanaRegion $r) => strcasecmp($r->value, $v('region')) === 0);
        if ($region === null) {
            throw new InvalidArgumentException("unknown region \"{$v('region')}\"");
        }
        if ($v('email') !== '' && filter_var($v('email'), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('email is not valid');
        }

        $customer = [
            'name' => mb_substr((string) preg_replace('/\s+/u', ' ', $v('name')), 0, 255),
            'type' => $type,
            'region' => $region->value,
            'district' => $v('district') ?: null,
            'address' => $v('address') ?: null,
            'contact_person' => $v('contact_person') ?: null,
            'phone' => $v('phone') ?: null,
            'email' => $v('email') ?: null,
            'credit_limit' => $v('credit_limit') === '' ? null : $this->ghs($v('credit_limit'), 'credit limit'),
            'notes' => $v('notes') ?: null,
        ];

        $openingBalance = null;
        if ($v('opening_balance') !== '') {
            $amount = $this->ghs($v('opening_balance'), 'opening balance');
            if ($amount <= 0) {
                throw new InvalidArgumentException('opening balance must be more than zero (leave it empty for none)');
            }
            $date = $v('opening_balance_date') === '' ? now()->toDateString() : $this->date($v('opening_balance_date'), 'opening balance date');
            if ($date > now()->toDateString()) {
                throw new InvalidArgumentException('opening balance date is in the future');
            }
            if ($v('opening_balance_due') === '') {
                throw new InvalidArgumentException('opening balance needs a due date (opening_balance_due)');
            }
            $openingBalance = ['amount' => $amount, 'date' => $date, 'due_date' => $this->date($v('opening_balance_due'), 'due date')];
        }

        return [$customer, $openingBalance];
    }

    private function ghs(string $value, string $field): int
    {
        try {
            $pesewas = Money::ghsToPesewas($value);
        } catch (Throwable) {
            throw new InvalidArgumentException("{$field} \"{$value}\" is not an amount in GHS with at most 2 decimals");
        }
        if ($pesewas > Money::MAX_PESEWAS) {
            throw new InvalidArgumentException("{$field} is too large");
        }

        return $pesewas;
    }

    private function date(string $value, string $field): string
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} \"{$value}\" must be written YYYY-MM-DD");
        }

        return $value;
    }

    /**
     * @return array<int, array<string, string>> keyed by file row number (header = 1)
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; // Excel's byte order mark
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');
        if ($header === false || $header === [null]) {
            throw new InvalidArgumentException('The file is empty.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        if (! in_array('name', $header, true) || ! in_array('region', $header, true)) {
            throw new InvalidArgumentException('The first row must be the column names (see the template); name and region are required.');
        }
        $unknown = array_diff(array_filter($header), self::COLUMNS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown column(s): '.implode(', ', $unknown).'.');
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($values === [null] || implode('', array_map('trim', array_map('strval', $values))) === '') {
                continue;
            }
            $rows[$line] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }
        fclose($handle);

        if ($rows === []) {
            throw new InvalidArgumentException('The file has no data rows.');
        }

        return $rows;
    }
}
