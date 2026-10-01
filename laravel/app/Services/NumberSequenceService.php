<?php

namespace App\Services;

use App\Models\NumberSequence;
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
     * First-row creation is race-safe: insert-or-ignore, then lockForUpdate.
     */
    public function next(string $key, int $year = 0): int
    {
        $allocate = function () use ($key, $year): int {
            $now = now();

            NumberSequence::query()->insertOrIgnore([
                'key' => $key,
                'year' => $year,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

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

    public function format(string $prefix, int $year, int $number, int $pad = 6): string
    {
        $padded = str_pad((string) $number, $pad, '0', STR_PAD_LEFT);

        if ($year === 0) {
            return sprintf('%s-%s', $prefix, $padded);
        }

        return sprintf('%s-%d-%s', $prefix, $year, $padded);
    }
}
