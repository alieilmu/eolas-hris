<?php

namespace App\Support\Attendance;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\Roster;
use App\Models\ShiftCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly table of Roster (the plan) or Absensi (what happened): one row per
 * employee, one cell per day holding a Kode Shift.
 */
class Sheet
{
    public const TYPES = ['roster', 'absensi'];

    public static function model(string $type): string
    {
        return $type === 'roster' ? Roster::class : Absensi::class;
    }

    /** Kode Shift in display order, for the page. */
    public static function codes(): array
    {
        return ShiftCode::orderBy('order')->orderBy('id')->get()
            ->map(fn (ShiftCode $c) => ['id' => $c->id, 'kode' => $c->kode, 'dws' => $c->dws, 'keterangan' => $c->keterangan, 'jenis' => $c->jenis])
            ->all();
    }

    /**
     * @return Collection<int, array<int, int>> karyawan id => [day => shift_code_id]
     */
    public static function cells(string $type, Period $period, ?Collection $karyawanIds = null): Collection
    {
        return self::model($type)::query()
            ->whereBetween('tanggal', [$period->start->toDateString(), $period->end()->toDateString()])
            ->when($karyawanIds, fn ($q) => $q->whereIn('karyawan_id', $karyawanIds))
            ->get(['karyawan_id', 'tanggal', 'shift_code_id'])
            ->groupBy('karyawan_id')
            ->map(fn (Collection $days) => $days->mapWithKeys(fn ($d) => [$d->tanggal->day => $d->shift_code_id])->all());
    }

    /** Every row of the month's table. */
    public static function rows(string $type, Period $period): array
    {
        $people = Roll::between($period->start, $period->end());
        $ids = $people->pluck('id');
        $cells = self::cells($type, $period, $ids);
        // Absensi is compared against the roster, and shows which days came from a trip.
        $plan = $type === 'absensi' ? self::cells('roster', $period, $ids) : collect();
        $tripDays = $type === 'absensi'
            ? Absensi::whereBetween('tanggal', [$period->start->toDateString(), $period->end()->toDateString()])
                ->whereIn('karyawan_id', $ids)->whereNotNull('trip_id')
                ->get(['karyawan_id', 'tanggal'])
                ->groupBy('karyawan_id')
                ->map(fn (Collection $d) => $d->map(fn ($a) => $a->tanggal->day)->values()->all())
            : collect();

        return $people->map(function (Karyawan $k) use ($type, $period, $cells, $plan, $tripDays) {
            [$from, $until] = self::employedDays($k, $period);
            // Days outside employment are shown blocked, never with codes.
            $within = fn (array $days) => array_filter($days, fn ($day) => $day >= $from && $day <= $until, ARRAY_FILTER_USE_KEY);
            $exit = Roll::exitDate($k);

            return [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'status' => $k->status,
                'jabatan' => $k->jabatan?->name,
                'departemen' => $k->departemen?->name,
                'departemen_id' => $k->departemen_id,
                'work_location' => $k->workLocation?->name,
                'work_location_id' => $k->work_location_id,
                'sub_location' => $k->subLocation?->name,
                'sub_location_id' => $k->sub_location_id,
                'from' => $from,
                'until' => $until,
                // From this day the row is blocked, labelled with the exit reason (Resign, Mutasi, ...).
                'keluar' => $exit && $period->contains($exit) ? ['tanggal' => $exit->toDateString(), 'alasan' => $k->alasan_keluar ?: 'Keluar'] : null,
                'cells' => (object) $within($cells[$k->id] ?? []),
                ...($type === 'absensi' ? [
                    'plan' => (object) $within($plan[$k->id] ?? []),
                    'trip_days' => array_values(array_filter($tripDays[$k->id] ?? [], fn ($day) => $day >= $from && $day <= $until)),
                ] : []),
            ];
        })->all();
    }

    /** The first and last day of the month the employee was employed (the exit day is no longer one). */
    public static function employedDays(Karyawan $k, Period $period): array
    {
        $from = 1;
        $until = $period->dayCount();
        $masuk = $k->profile?->tanggal_masuk;
        if ($masuk && $period->contains(CarbonImmutable::parse($masuk))) {
            $from = $masuk->day;
        }
        $exit = Roll::exitDate($k);
        if ($exit && $period->contains($exit)) {
            $until = $exit->day - 1;
        }

        return [$from, $until];
    }
}
