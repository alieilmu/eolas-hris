<?php

namespace App\Support\Attendance;

/**
 * Per-employee percentages of a month of Absensi (mirrored in the page's lib/attendance.ts):
 *  - % Kehadiran: days worked (hadir + dinas) out of the days they were due to
 *    work — off and cuti are not counted against them.
 *  - % Sesuai Roster: days whose Absensi code equals the Roster's, out of the
 *    days both are filled.
 * Only days up to today count.
 */
class Stats
{
    /**
     * @param  array<int, int>  $cells  day => shift_code_id (Absensi)
     * @param  array<int, int>  $plan  day => shift_code_id (Roster)
     * @param  array<int, string>  $jenis  shift_code_id => jenis
     * @return array{kehadiran: float|null, sesuai: float|null}
     */
    public static function of(array $cells, array $plan, array $jenis, int $lastDay): array
    {
        $worked = 0;
        $due = 0;
        $same = 0;
        $compared = 0;
        foreach ($cells as $day => $code) {
            if ($day > $lastDay) {
                continue;
            }
            $kind = $jenis[$code] ?? null;
            if (in_array($kind, ['hadir', 'dinas'], true)) {
                $worked++;
            }
            if (in_array($kind, ['hadir', 'dinas', 'sakit', 'izin', 'mangkir'], true)) {
                $due++;
            }
            if (isset($plan[$day])) {
                $compared++;
                $same += (int) $plan[$day] === (int) $code ? 1 : 0;
            }
        }

        return [
            'kehadiran' => $due ? round($worked / $due * 100, 1) : null,
            'sesuai' => $compared ? round($same / $compared * 100, 1) : null,
        ];
    }

    /** The last day of the period that has happened: all of a past month, none of a future one. */
    public static function lastDay(Period $period): int
    {
        $today = now()->startOfDay();

        return match (true) {
            $period->end()->lt($today) => $period->dayCount(),
            $period->start->gt($today) => 0,
            default => $today->day,
        };
    }
}
