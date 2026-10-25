<?php

namespace App\Support\TimeClock;

use App\Models\ShiftCode;
use Carbon\CarbonImmutable;

/**
 * The schedule of a shift on a date: start, end (the next day for a night shift) and the
 * break, also moved to the next day when it falls after midnight.
 */
final class ShiftWindow
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?CarbonImmutable $breakStart,
        public readonly ?CarbonImmutable $breakEnd,
    ) {}

    public static function of(ShiftCode $code, CarbonImmutable $date): ?self
    {
        if (! $code->scheduled()) {
            return null;
        }
        $at = fn (string $time) => CarbonImmutable::parse($date->toDateString().' '.$time);
        $start = $at($code->start_time);
        $end = $at($code->end_time);
        if ($end->lte($start)) {
            $end = $end->addDay();
        }
        [$breakStart, $breakEnd] = [null, null];
        if ($code->break_start && $code->break_end) {
            $breakStart = $at($code->break_start);
            if ($breakStart->lt($start)) {
                $breakStart = $breakStart->addDay();
            }
            $breakEnd = $at($code->break_end);
            while ($breakEnd->lte($breakStart)) {
                $breakEnd = $breakEnd->addDay();
            }
        }

        return new self($start, $end, $breakStart, $breakEnd);
    }

    /** Minutes of the break inside [$a, $b]: these are never working time. */
    public function breakWithin(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return $this->breakStart ? self::overlap($a, $b, $this->breakStart, $this->breakEnd) : 0;
    }

    /** Scheduled working minutes (the shift minus its break). */
    public function workedMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end) - $this->breakWithin($this->start, $this->end);
    }

    public static function overlap(CarbonImmutable $a1, CarbonImmutable $a2, CarbonImmutable $b1, CarbonImmutable $b2): int
    {
        $start = $a1->max($b1);
        $end = $a2->min($b2);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }
}
