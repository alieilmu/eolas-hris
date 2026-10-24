<?php

namespace Database\Seeders;

use App\Models\Golongan;
use App\Models\Karyawan;
use App\Models\SkalaUpah;
use App\Models\SkalaUpahKolom;
use App\Models\SkalaUpahNilai;
use App\Models\SubGroup;
use App\Models\TunjanganJabatan;
use App\Support\Payroll\Gaji;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo salaries: the Struktur Skala Upah completed (the STAFF golongan had no amounts, and
 * a general 2026 column at +5%), Tunjangan Jabatan per SubGroup, and every employee placed
 * on their golongan (a step of it for the NONOM scale), some raised a step this year.
 */
class EolasGajiDemoSeeder extends Seeder
{
    /** 2025 gaji pokok of the STAFF golongan. */
    private const STAFF = [
        '3A' => 5800000, '3B' => 6200000, '3C' => 6700000, '3D' => 7200000, '3E' => 7800000,
        '4A' => 8600000, '4B' => 9500000, '4C' => 10500000, '4D' => 11600000, '4E' => 12800000,
        '5A' => 15000000, '5B' => 17500000, '5C' => 20500000, '5D' => 24000000, '5E' => 28000000,
    ];

    private const TUNJANGAN = [
        'BOD' => 25000000, 'General Manager' => 15000000, 'Manager' => 8000000, 'Project Manager' => 10000000,
        'Deputy Project Manager' => 7000000, 'Departement Head' => 6000000, 'Section Head HO' => 4000000,
        'Section Head Site' => 4500000, 'Officer HO/ Group Leader' => 2000000, 'Junior Staff' => 1000000,
        'FGDP' => 750000, 'Operator' => 500000, 'Mekanik' => 600000, 'Welder' => 600000, 'Nonom' => 300000,
    ];

    /** Golongan by SubGroup for an employee whose profile has none. */
    private const GOLONGAN_SUBGROUP = ['Operator' => 'D2J', 'Mekanik' => 'G2J', 'Welder' => 'W2J', 'Nonom' => '1C'];

    public function run(): void
    {
        mt_srand(2028);
        $k2025 = SkalaUpahKolom::where('tahun', 2025)->whereNull('work_location_id')->first();
        if ($k2025) {
            foreach (self::STAFF as $kode => $nominal) {
                $golongan = Golongan::where('kode', $kode)->first();
                foreach ($golongan?->skalaUpahs ?? [] as $s) {
                    SkalaUpahNilai::firstOrCreate(['skala_upah_id' => $s->id, 'kolom_id' => $k2025->id], ['nominal' => $nominal]);
                }
            }
            $k2026 = SkalaUpahKolom::firstOrCreate(['tahun' => 2026, 'work_location_id' => null], ['urutan' => (SkalaUpahKolom::max('urutan') ?? 0) + 1]);
            foreach (SkalaUpahNilai::where('kolom_id', $k2025->id)->get() as $n) {
                SkalaUpahNilai::firstOrCreate(['skala_upah_id' => $n->skala_upah_id, 'kolom_id' => $k2026->id], ['nominal' => (int) (round($n->nominal * 1.05 / 1000) * 1000)]);
            }
        }

        foreach (SubGroup::all() as $g) {
            TunjanganJabatan::firstOrCreate(['sub_group_id' => $g->id], ['nominal' => self::TUNJANGAN[$g->nama] ?? 0]);
        }

        $skala = SkalaUpah::with('golongan')->orderBy('urutan')->orderBy('id')->get()->groupBy(fn (SkalaUpah $s) => $s->golongan->kode);
        $today = CarbonImmutable::today();
        foreach (Karyawan::withoutGlobalScope('akses')->with(['profile', 'subGroup'])->doesntHave('gajis')->get() as $k) {
            $kode = $k->profile?->golongan ?: (self::GOLONGAN_SUBGROUP[$k->subGroup?->nama] ?? null);
            $lines = $kode ? ($skala[$kode] ?? collect()) : collect();
            if ($lines->isEmpty()) {
                continue;
            }
            $i = mt_rand(0, max(0, intdiv($lines->count() - 1, 2)));
            $masuk = $k->profile?->tanggal_masuk ?? $today->subYears(2)->startOfYear();
            $k->gajis()->create(['skala_upah_id' => $lines[$i]->id, 'berlaku_mulai' => $masuk->toDateString(), 'alasan' => 'awal']);
            if (isset($lines[$i + 1]) && $masuk->lt($today->startOfYear()) && mt_rand(1, 100) <= 40) {
                $k->gajis()->create(['skala_upah_id' => $lines[$i + 1]->id, 'berlaku_mulai' => $today->startOfYear()->toDateString(), 'alasan' => 'kenaikan', 'catatan' => 'Kenaikan step tahunan']);
            }
            if (! $k->profile?->golongan && $k->profile) {
                $k->profile->update(['golongan' => $kode]);
            }
        }
        Gaji::flush();
    }
}
