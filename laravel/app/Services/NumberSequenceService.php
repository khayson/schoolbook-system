<?php

namespace App\Services;

use App\Models\NumberSequence;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NumberSequenceService
{
    /**
     * Allocate the next number for a sequence key, locking the row.
     *
     * Uses year 0 for "no year" sequences (e.g. CUS-0001).
     * When already inside a transaction (e.g. ReceiveStock), increments in that
     * same transaction so a rollback never burns a number.
     *
     * The counter row is guaranteed to exist *before* the caller's transaction touches
     * it (see ensureRowExists), so the transaction takes exactly one lock on it, an
     * exclusive one, and concurrent allocators simply queue.
     */
    public function next(string $key, int $year = 0): int
    {
        $this->ensureRowExists($key, $year);

        $allocate = function () use ($key, $year): int {
            $sequence = NumberSequence::query()
                ->where('key', $key)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException("Unable to lock number sequence [{$key}:{$year}].");
            }

            $sequence->last_number++;
            $sequence->save();

            return (int) $sequence->last_number;
        };

        if (DB::transactionLevel() > 0) {
            return $allocate();
        }

        return DB::transaction($allocate);
    }

    /**
     * Creates the counter row if missing, outside the caller's transaction.
     *
     * Why: INSERT IGNORE inside the business transaction takes a shared lock on the
     * existing row (InnoDB's duplicate-key check) and keeps it until commit. Two
     * transactions that both hold it and then ask for FOR UPDATE wait on each other:
     * deadlock (1213). Found by the 2C.2 concurrent-receipt test. On MySQL/MariaDB the
     * row is therefore created on a separate autocommit connection, where that shared
     * lock lasts one statement. The existence check is a plain (non-locking) read, so
     * in the steady state this adds no lock at all. SQLite has no row locks and a single
     * writer, so it uses the default connection.
     */
    public function prepare(string $key, int $year): void
    {
        $this->ensureRowExists($key, $year);
    }

    private function ensureRowExists(string $key, int $year): void
    {
        $connection = $this->sequenceConnection();

        $exists = $connection->table('number_sequences')
            ->where('key', $key)
            ->where('year', $year)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        $connection->table('number_sequences')->insertOrIgnore([
            'key' => $key,
            'year' => $year,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function sequenceConnection(): ConnectionInterface
    {
        $main = DB::connection();

        if (! in_array($main->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $main;
        }

        $name = $main->getName().'__sequences';
        if (config("database.connections.{$name}") === null) {
            config(["database.connections.{$name}" => config("database.connections.{$main->getName()}")]);
        }

        return DB::connection($name);
    }

    public function format(string $prefix, int $year, int $number, int $pad = 6): string
    {
        $padded = str_pad((string) $number, $pad, '0', STR_PAD_LEFT);

        if ($year === 0) {
            return sprintf('%s-%s', $prefix, $padded);
        }

        return sprintf('%s-%d-%s', $prefix, $year, $padded);
    }
}
