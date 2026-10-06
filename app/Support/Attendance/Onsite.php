<?php

namespace App\Support\Attendance;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\Roster;
use App\Models\ShiftCode;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Man Onsite: where each employee is on each day. A Trip & Leave entry wins
 * (dinas / cuti); otherwise Absensi says it, and where Absensi is still empty
 * (e.g. days ahead) the Roster stands in as the plan.
 */
class Onsite
{
    /** How a Kode Shift's jenis is counted. */
    private const STATUS = [
        'hadir' => 'onsite',
        'dinas' => 'dinas',
        'cuti' => 'cuti',
        'off' => 'off',
        'sakit' => 'absen',
        'izin' => 'absen',
        'mangkir' => 'absen',
    ];

    public const KEYS = ['onsite', 'dinas', 'cuti', 'off', 'absen', 'kosong'];

    /**
     * One entry per day: how many people are counted, and how they split.
     *
     * @return list<array<string, mixed>>
     */
    public static function daily(CarbonImmutable $from, CarbonImmutable $to, ?int $workLocationId, int|string|null $subLocation = null): array
    {
        $people = Roll::between($from, $to, $workLocationId, $subLocation);
        $map = self::statusMap($people, $from, $to);
        // Each person's employed stretch as Y-m-d strings, compared as text in the loop.
        $spans = $people->map(fn ($k) => [
            $k->id,
            $k->profile?->tanggal_masuk?->toDateString() ?? '0000-00-00',
            // The day before the exit date is the last working day.
            Roll::exitDate($k)?->subDay()->toDateString() ?? '9999-12-31',
        ])->all();

        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $counts = array_fill_keys(self::KEYS, 0);
            $fromPlan = 0;
            $total = 0;
            foreach ($spans as [$id, $start, $end]) {
                if ($key < $start || $key > $end) {
                    continue;
                }
                $total++;
                [$status, $plan] = $map[$id][$key] ?? ['kosong', false];
                $counts[$status]++;
                $fromPlan += $plan ? 1 : 0;
            }
            $days[] = ['date' => $key, 'total' => $total, ...$counts, 'from_plan' => $fromPlan];
        }

        return $days;
    }

    /**
     * Each employee's status per day.
     *
     * @return array<int, array<string, array{0: string, 1: bool}>> karyawan id => date => [status, from the roster]
     */
    public static function statusMap(Collection $people, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $people->pluck('id');
        $jenis = ShiftCode::pluck('jenis', 'id');
        $range = [$from->toDateString(), $to->toDateString()];

        $map = [];
        foreach (Roster::whereBetween('tanggal', $range)->whereIn('karyawan_id', $ids)->get(['karyawan_id', 'tanggal', 'shift_code_id']) as $r) {
            $map[$r->karyawan_id][$r->tanggal->toDateString()] = [self::STATUS[$jenis[$r->shift_code_id]] ?? 'kosong', true];
        }
        foreach (Absensi::whereBetween('tanggal', $range)->whereIn('karyawan_id', $ids)->get(['karyawan_id', 'tanggal', 'shift_code_id']) as $a) {
            $map[$a->karyawan_id][$a->tanggal->toDateString()] = [self::STATUS[$jenis[$a->shift_code_id]] ?? 'kosong', false];
        }
        foreach (Trip::disetujui()->overlapping($from, $to)->whereIn('karyawan_id', $ids)->get() as $t) {
            $start = $t->tanggal_awal->max($from);
            $end = $t->tanggal_akhir->min($to);
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                $map[$t->karyawan_id][$d->toDateString()] = [$t->jenis, false];
            }
        }

        return $map;
    }

    /** People away today (dinas / cuti), with where they went and until when. */
    public static function awayToday(?int $workLocationId, CarbonImmutable $today, int|string|null $subLocation = null): array
    {
        return Trip::with(['karyawan.jabatan:id,name', 'karyawan.workLocation:id,name'])
            ->disetujui()
            ->overlapping($today, $today)
            ->when($workLocationId, fn ($q) => $q->whereHas('karyawan', fn ($k) => $k->where('work_location_id', $workLocationId)))
            ->when($subLocation !== null, fn ($q) => $q->whereHas('karyawan', fn ($k) => Roll::whereSubLocation($k, $subLocation)))
            ->orderBy('tanggal_akhir')
            ->get()
            ->map(fn (Trip $t) => [
                'id' => $t->id,
                'jenis' => $t->jenis,
                'nrp' => $t->karyawan->nrp,
                'name' => $t->karyawan->name,
                'jabatan' => $t->karyawan->jabatan?->name,
                'work_location' => $t->karyawan->workLocation?->name,
                'kota_tujuan' => $t->kota_tujuan,
                'tanggal_awal' => $t->tanggal_awal->toDateString(),
                'tanggal_akhir' => $t->tanggal_akhir->toDateString(),
                'sisa_hari' => (int) $today->diffInDays($t->tanggal_akhir),
            ])
            ->all();
    }

    /** The employee's status today, by their id (for the trip form). */
    public static function todayOf(Karyawan $k, CarbonImmutable $today): string
    {
        return self::statusMap(collect([$k]), $today, $today)[$k->id][$today->toDateString()][0] ?? 'kosong';
    }
}
