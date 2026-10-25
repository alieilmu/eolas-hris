<?php

namespace App\Support\Payroll;

use Carbon\CarbonImmutable;

/**
 * A payroll month ("2026-10") and the dates it covers. With cutoff day 20 the October
 * period runs 21 September – 20 October; with 31 (or any day past the month's end) it is
 * the calendar month.
 */
final class PayrollPeriod
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function of(int $year, int $month, int $cutoffDay): self
    {
        $endOf = fn (int $y, int $m) => CarbonImmutable::create($y, $m, 1)->day(min($cutoffDay, CarbonImmutable::create($y, $m, 1)->daysInMonth));
        $end = $endOf($year, $month);
        $previous = CarbonImmutable::create($year, $month, 1)->subMonth();

        return new self($year, $month, $endOf($previous->year, $previous->month)->addDay(), $end);
    }

    /** "2026-10" → the period; null when the text is not a month. */
    public static function fromCode(?string $code, int $cutoffDay): ?self
    {
        return $code && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $code, $m) ? self::of((int) $m[1], (int) $m[2], $cutoffDay) : null;
    }

    public function code(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function label(): string
    {
        return self::MONTHS[$this->month - 1].' '.$this->year;
    }

    public function range(): string
    {
        return $this->start->format('d.m.Y').' – '.$this->end->format('d.m.Y');
    }

    /** December: the month PPh21 is worked out for the whole year. */
    public function isLastOfYear(): bool
    {
        return $this->month === 12;
    }
}
