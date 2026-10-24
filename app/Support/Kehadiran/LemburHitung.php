<?php

namespace App\Support\Kehadiran;

use App\Models\LemburAturan;

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
 * (Master Data Attendance). The hourly wage (1/173 of the monthly fixed pay) is applied
 * by Payroll to "jam terhitung" = the hours weighted by these multipliers.
 */
class LemburHitung
{
    /**
     * @return array{menit_lembur: int, jam_dibayar: float, jam_terhitung: float, rincian: list<array{jam: float, kali: float}>}
     */
    public static function hitung(string $jenisHari, int $menitKerja, string $skema, float $jamNormal, LemburAturan $aturan): array
    {
        $menit = $jenisHari === 'kerja' ? max(0, $menitKerja - (int) round($jamNormal * 60)) : max(0, $menitKerja);
        $unit = max(1, (int) $aturan->pembulatan_menit);
        $menit = intdiv($menit, $unit) * $unit;
        if ($menit < (int) $aturan->minimal_menit) {
            $menit = 0;
        }
        $jam = $menit / 60;

        $tiers = $jenisHari === 'kerja'
            ? [[1, 1.5], [INF, 2]]
            : ($skema === '5_hari' ? [[8, 2], [1, 3], [INF, 4]] : [[7, 2], [1, 3], [INF, 4]]);

        $sisa = $jam;
        $rincian = [];
        $terhitung = 0.0;
        foreach ($tiers as [$panjang, $kali]) {
            if ($sisa <= 0) {
                break;
            }
            $bagian = min($sisa, $panjang);
            $rincian[] = ['jam' => round($bagian, 2), 'kali' => $kali];
            $terhitung += $bagian * $kali;
            $sisa -= $bagian;
        }

        return ['menit_lembur' => $menit, 'jam_dibayar' => round($jam, 2), 'jam_terhitung' => round($terhitung, 2), 'rincian' => $rincian];
    }
}
