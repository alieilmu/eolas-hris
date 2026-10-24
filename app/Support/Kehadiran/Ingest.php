<?php

namespace App\Support\Kehadiran;

use App\Models\Karyawan;
use App\Models\MesinAbsen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes clock-ins in (from a machine, a file or HR), matched to employees by their PIN
 * (pin_absen, else NRP), ignoring ones already there (same PIN and time), and then
 * reprocesses the days they touch.
 */
class Ingest
{
    /**
     * @param  list<array{pin: string, waktu: string, status?: ?int, verify?: ?string}>  $rows
     * @return array{diterima: int, tanpa_karyawan: int, duplikat: int}
     */
    public static function store(array $rows, ?MesinAbsen $mesin, string $sumber, ?int $userId = null, ?string $catatan = null): array
    {
        $pins = collect($rows)->pluck('pin')->unique()->values();
        $byPin = Karyawan::withoutGlobalScope('akses')->where(fn ($q) => $q->whereIn('pin_absen', $pins)->orWhere(fn ($q) => $q->whereNull('pin_absen')->whereIn('nrp', $pins)))
            ->get(['id', 'nrp', 'pin_absen'])->mapWithKeys(fn (Karyawan $k) => [$k->pin() => $k->id]);
        $now = now();
        $insert = [];
        $tanpa = 0;
        foreach ($rows as $r) {
            $kid = $byPin[$r['pin']] ?? null;
            $tanpa += $kid ? 0 : 1;
            $insert[] = [
                'mesin_absen_id' => $mesin?->id, 'pin' => $r['pin'], 'karyawan_id' => $kid,
                'waktu' => CarbonImmutable::parse($r['waktu'])->format('Y-m-d H:i:s'),
                'status_code' => $r['status'] ?? null, 'verify' => $r['verify'] ?? null,
                'sumber' => $sumber, 'catatan' => $catatan, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $diterima = 0;
        foreach (array_chunk($insert, 500) as $part) {
            $diterima += DB::table('absen_logs')->insertOrIgnore($part);
        }

        // Reprocess the days touched (a night shift's CO belongs to the day before).
        $touched = collect($insert)->filter(fn ($r) => $r['karyawan_id']);
        if ($diterima > 0 && $touched->isNotEmpty()) {
            $dates = $touched->pluck('waktu')->map(fn ($w) => substr($w, 0, 10))->sort()->values();
            Proses::run(CarbonImmutable::parse($dates->first())->subDay(), CarbonImmutable::parse($dates->last()), $touched->pluck('karyawan_id')->unique()->values()->all());
        }

        return ['diterima' => $diterima, 'tanpa_karyawan' => $tanpa, 'duplikat' => count($insert) - $diterima];
    }
}
