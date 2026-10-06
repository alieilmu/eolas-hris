<?php

namespace App\Support\Comben;

use App\Support\Attendance\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Comben book month closes on the 8th: a deklarasi dated 9 September – 8
 * October is booked to October. The plafon year is the book month's year, so
 * 9–31 December already counts against the next year's plafon.
 */
class BukuBulan
{
    public const TUTUP_TANGGAL = 8;

    /** "2026-10" for any date from 09.09.2026 to 08.10.2026. */
    public static function of(CarbonInterface $tanggal): string
    {
        $date = CarbonImmutable::parse($tanggal->toDateString());

        return ($date->day <= self::TUTUP_TANGGAL ? $date : $date->startOfMonth()->addMonth())->format('Y-m');
    }

    public static function tahun(string $bulan): int
    {
        return (int) substr($bulan, 0, 4);
    }

    /** The twelve book months of a plafon year. */
    public static function months(int $tahun): array
    {
        return array_map(fn (int $m) => sprintf('%04d-%02d', $tahun, $m), range(1, 12));
    }

    /** "Oktober 2026" */
    public static function label(string $bulan): string
    {
        return Period::from($bulan)->label();
    }

    /** "09.09.2026 – 08.10.2026" */
    public static function span(string $bulan): string
    {
        $end = CarbonImmutable::parse("{$bulan}-01")->setDay(self::TUTUP_TANGGAL);

        return $end->subMonth()->addDay()->format('d.m.Y').' – '.$end->format('d.m.Y');
    }

    /** The plafon year of today's book month. */
    public static function currentTahun(): int
    {
        return self::tahun(self::of(CarbonImmutable::today()));
    }
}
