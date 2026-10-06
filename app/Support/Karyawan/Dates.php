<?php

namespace App\Support\Karyawan;

use Carbon\CarbonInterface;

/** Day-granular date helpers shared by the dashboard and the table. */
class Dates
{
    /** Whole days from $today to $date; negative once $date has passed. */
    public static function daysUntil(CarbonInterface $date, CarbonInterface $today): int
    {
        return (int) $today->startOfDay()->diffInDays($date->startOfDay(), false);
    }

    /** True when $date is today or falls within the next $months calendar months. */
    public static function isWithinMonths(CarbonInterface $date, int $months, CarbonInterface $today): bool
    {
        $start = $today->startOfDay();

        return $date->gte($start) && $date->lte($start->addMonthsNoOverflow($months));
    }
}
