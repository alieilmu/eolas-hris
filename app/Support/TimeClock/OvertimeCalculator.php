<?php

namespace App\Support\TimeClock;

use App\Models\OvertimePolicy;

/**
 * Overtime hours by Indonesian law (PP 35/2021, art. 26–31), from the effective working
 * minutes of a day (clock in to clock out, the break never counted as work):
 *
 *  - hari kerja: work beyond the normal hours of the location's scheme (8 h in a 5-day
 *    week, 7 h in a 6-day week or the ESDM roster scheme of Kepmenakertrans 234/2003) is
 *    overtime; the first hour × 1.5, every next hour × 2;
 *  - hari istirahat (OFF in the roster) and hari libur nasional: all work is overtime;
 *    5-day scheme: hours 1–8 × 2, hour 9 × 3, hours 10+ × 4;
 *    6-day / ESDM scheme: hours 1–7 × 2, hour 8 × 3, hours 9+ × 4.
 *
 * Overtime is rounded down to the rounding unit and dropped below the minimum
 * (Pengaturan Waktu & Kehadiran). The hourly wage (1/173 of the monthly fixed pay) is applied
 * by Payroll to "jam terhitung" = the hours weighted by these multipliers.
 */
class OvertimeCalculator
{
    /**
     * @return array{menit_lembur: int, jam_dibayar: float, jam_terhitung: float, rincian: list<array{jam: float, kali: float}>}
     */
    public static function calculate(string $jenisHari, int $workedMinutes, string $scheme, float $jamNormal, OvertimePolicy $policy): array
    {
        $menit = $jenisHari === 'workday' ? max(0, $workedMinutes - (int) round($jamNormal * 60)) : max(0, $workedMinutes);
        $unit = max(1, (int) $policy->rounding_minutes);
        $menit = intdiv($menit, $unit) * $unit;
        if ($menit < (int) $policy->minimum_minutes) {
            $menit = 0;
        }
        $hours = $menit / 60;

        $tiers = $jenisHari === 'workday'
            ? [[1, 1.5], [INF, 2]]
            : ($scheme === 'five_day' ? [[8, 2], [1, 3], [INF, 4]] : [[7, 2], [1, 3], [INF, 4]]);

        $remaining = $hours;
        $breakdown = [];
        $weighted = 0.0;
        foreach ($tiers as [$panjang, $times]) {
            if ($remaining <= 0) {
                break;
            }
            $bagian = min($remaining, $panjang);
            $breakdown[] = ['hours' => round($bagian, 2), 'times' => $times];
            $weighted += $bagian * $times;
            $remaining -= $bagian;
        }

        return ['overtime_minutes' => $menit, 'paid_hours' => round($hours, 2), 'weighted_hours' => round($weighted, 2), 'breakdown' => $breakdown];
    }
}
