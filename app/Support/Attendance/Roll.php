<?php

namespace App\Support\Attendance;

use App\Models\Karyawan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who is on the attendance tables for a stretch of days: everyone employed for
 * at least one of them (joined by its end, not gone before its start).
 */
class Roll
{
    /** The Sub Lokasi filter value for "at the site itself, not in a Sub Lokasi". */
    public const NO_SUB_LOCATION = 'none';

    /**
     * @param  int|string|null  $subLocation  a Sub Lokasi id, NO_SUB_LOCATION, or null for everyone
     * @return Collection<int, Karyawan>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, ?int $workLocationId = null, int|string|null $subLocation = null): Collection
    {
        return Karyawan::query()
            ->with(['jabatan:id,name', 'departemen:id,name', 'workLocation:id,name,order', 'subLocation:id,name', 'profile:id,karyawan_id,tanggal_masuk'])
            ->when($workLocationId, fn ($q) => $q->where('work_location_id', $workLocationId))
            ->tap(fn ($q) => self::whereSubLocation($q, $subLocation))
            // Someone who left is listed only while they still have a working day: the exit day itself is not one.
            ->where(fn ($q) => $q->where('status', '!=', Karyawan::STATUS_KELUAR)
                ->orWhereDate('tanggal_keluar', '>', $from->toDateString()))
            ->get()
            ->filter(fn (Karyawan $k) => ! $k->profile?->tanggal_masuk || $k->profile->tanggal_masuk->lte($to))
            ->sortBy([
                fn (Karyawan $a, Karyawan $b) => ($a->workLocation?->order ?? PHP_INT_MAX) <=> ($b->workLocation?->order ?? PHP_INT_MAX),
                fn (Karyawan $a, Karyawan $b) => strcmp($a->departemen?->name ?? "\u{FFFF}", $b->departemen?->name ?? "\u{FFFF}"),
                fn (Karyawan $a, Karyawan $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /** Whether the employee was employed on $date. */
    public static function employedOn(Karyawan $k, CarbonImmutable $date): bool
    {
        $masuk = $k->profile?->tanggal_masuk;
        if ($masuk && $date->lt($masuk)) {
            return false;
        }

        return ! (($exit = self::exitDate($k)) && $date->gte($exit));
    }

    /** The first day an employee who left no longer works (their exit date), or null. */
    public static function exitDate(Karyawan $k): ?CarbonImmutable
    {
        return $k->status === Karyawan::STATUS_KELUAR && $k->tanggal_keluar ? CarbonImmutable::parse($k->tanggal_keluar->toDateString()) : null;
    }

    /**
     * A check of whether an employee worked on a day: not before joining, not from the exit date on.
     *
     * @param  iterable<int>  $karyawanIds
     * @return \Closure(int, string): bool karyawan id, Y-m-d
     */
    public static function workingDays(iterable $karyawanIds): \Closure
    {
        $spans = Karyawan::with('profile:id,karyawan_id,tanggal_masuk')->whereIn('id', collect($karyawanIds)->all())->get()
            ->mapWithKeys(fn (Karyawan $k) => [$k->id => [$k->profile?->tanggal_masuk?->toDateString(), self::exitDate($k)?->toDateString()]]);

        return function (int $id, string $date) use ($spans): bool {
            [$masuk, $exit] = $spans[$id] ?? [null, null];

            return (! $masuk || $date >= $masuk) && (! $exit || $date < $exit);
        };
    }

    /** Narrows a karyawans query to a Sub Lokasi, or to those outside any (NO_SUB_LOCATION); null leaves it. */
    public static function whereSubLocation(Builder $query, int|string|null $subLocation): void
    {
        if ($subLocation === self::NO_SUB_LOCATION) {
            $query->whereNull('sub_location_id');
        } elseif ($subLocation !== null && $subLocation !== '') {
            $query->where('sub_location_id', (int) $subLocation);
        }
    }

    /** The ?sub= query value: a Sub Lokasi id, NO_SUB_LOCATION, or null. */
    public static function subLocationFilter(?string $value): int|string|null
    {
        return match (true) {
            $value === self::NO_SUB_LOCATION => self::NO_SUB_LOCATION,
            $value !== null && ctype_digit($value) => (int) $value,
            default => null,
        };
    }
}
