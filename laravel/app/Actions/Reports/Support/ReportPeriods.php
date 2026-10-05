<?php

namespace App\Actions\Reports\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Period helpers for the reports. Dates are Africa/Accra calendar dates (UTC+0 all
 * year, the app timezone). Ranges are inclusive and are queried half-open on the next
 * day (>= from, < to + 1 day), which works for DATE columns and datetimes on both MySQL
 * and SQLite and keeps the column index usable.
 */
final class ReportPeriods
{
    public const GROUPINGS = ['day', 'week', 'month'];

    /** First instant of $from and the first instant after $to, as 'Y-m-d' strings. */
    public static function bounds(string $from, string $to): array
    {
        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->addDay()->toDateString()];
    }

    /** Bucket key of a date: the day, the Monday of its week, or Y-m. */
    public static function key(string $date, string $grouping): string
    {
        $d = Carbon::parse($date);

        return match ($grouping) {
            'day' => $d->toDateString(),
            'week' => $d->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => $d->format('Y-m'),
            default => throw new InvalidArgumentException("Unknown grouping [{$grouping}]."),
        };
    }

    /**
     * Every bucket key in the range, in order, so empty periods are listed with zeros.
     *
     * @return list<string>
     */
    public static function keys(string $from, string $to, string $grouping): array
    {
        $keys = [];
        $day = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($day->lte($end)) {
            $keys[self::key($day->toDateString(), $grouping)] = true;
            $day->addDay();
        }

        return array_keys($keys);
    }
}
