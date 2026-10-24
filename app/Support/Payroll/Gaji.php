<?php

namespace App\Support\Payroll;

use App\Models\Karyawan;
use App\Models\KaryawanGaji;
use App\Models\SkalaUpahKolom;
use App\Models\SkalaUpahNilai;
use App\Models\TunjanganJabatan;
use Carbon\CarbonImmutable;

/**
 * An employee's fixed pay: Gaji Pokok from the Struktur Skala Upah (the golongan + step of
 * their latest KaryawanGaji up to a date, in the scale column of their work location and
 * year), and Tunjangan Jabatan of their SubGroup. Upah tetap = both together, the basis of
 * BPJS, THR and the hourly overtime wage (1/173).
 */
class Gaji
{
    /** @var array<string, ?int> */
    private static array $nominal = [];

    /** @var array<int, int>|null sub_group_id => nominal */
    private static ?array $tunjangan = null;

    public static function flush(): void
    {
        self::$nominal = [];
        self::$tunjangan = null;
    }

    /** The KaryawanGaji in force on $on (the latest one starting on or before it). */
    public static function posisi(Karyawan $k, ?CarbonImmutable $on = null): ?KaryawanGaji
    {
        $on ??= CarbonImmutable::today();
        $rows = $k->relationLoaded('gajis') ? $k->gajis : $k->gajis()->with('skalaUpah.golongan')->get();

        return $rows->first(fn (KaryawanGaji $g) => $g->berlaku_mulai->lte($on));
    }

    public static function pokok(Karyawan $k, ?CarbonImmutable $on = null): ?int
    {
        $on ??= CarbonImmutable::today();
        $posisi = self::posisi($k, $on);

        return $posisi ? self::nominal($posisi->skala_upah_id, $k->work_location_id, $on->year) : null;
    }

    /** A scale line's wage: the latest column up to $tahun for the location, else the general one. */
    public static function nominal(int $skalaUpahId, ?int $workLocationId, int $tahun): ?int
    {
        $key = "{$skalaUpahId}:{$workLocationId}:{$tahun}";
        if (array_key_exists($key, self::$nominal)) {
            return self::$nominal[$key];
        }
        $kolomIds = SkalaUpahKolom::where('tahun', '<=', $tahun)
            ->where(fn ($q) => $q->whereNull('work_location_id')->when($workLocationId, fn ($q) => $q->orWhere('work_location_id', $workLocationId)))
            ->orderByDesc('tahun')->orderByRaw('work_location_id is null')
            ->pluck('id');
        $nilai = SkalaUpahNilai::where('skala_upah_id', $skalaUpahId)->whereIn('kolom_id', $kolomIds)->pluck('nominal', 'kolom_id');
        $found = null;
        foreach ($kolomIds as $id) {
            if (isset($nilai[$id])) {
                $found = (int) $nilai[$id];
                break;
            }
        }

        return self::$nominal[$key] = $found;
    }

    public static function tunjanganJabatan(Karyawan $k): int
    {
        self::$tunjangan ??= TunjanganJabatan::pluck('nominal', 'sub_group_id')->map(fn ($n) => (int) $n)->all();

        return $k->sub_group_id ? (self::$tunjangan[$k->sub_group_id] ?? 0) : 0;
    }

    /** Gaji pokok + tunjangan tetap; null when the gaji pokok is not known. */
    public static function upahTetap(Karyawan $k, ?CarbonImmutable $on = null): ?int
    {
        $pokok = self::pokok($k, $on);

        return $pokok === null ? null : $pokok + self::tunjanganJabatan($k);
    }
}
