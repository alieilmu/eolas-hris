<?php

namespace App\Support\Time;

use App\Models\Employee;
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
        return Employee::query()
            ->with(['position:id,name', 'department:id,name', 'workLocation:id,name,sort_order', 'subLocation:id,name', 'profile:id,employee_id,hire_date'])
            ->when($workLocationId, fn ($q) => $q->where('work_location_id', $workLocationId))
            ->tap(fn ($q) => self::whereSubLocation($q, $subLocation))
            // Someone who left is listed only while they still have a working day: the exit day itself is not one.
            ->where(fn ($q) => $q->where('status', '!=', Employee::STATUS_TERMINATED)
                ->orWhereDate('termination_date', '>', $from->toDateString()))
            ->get()
            ->filter(fn (Employee $k) => ! $k->profile?->hire_date || $k->profile->hire_date->lte($to))
            ->sortBy([
                fn (Employee $a, Employee $b) => ($a->workLocation?->sort_order ?? PHP_INT_MAX) <=> ($b->workLocation?->sort_order ?? PHP_INT_MAX),
                fn (Employee $a, Employee $b) => strcmp($a->department?->name ?? "\u{FFFF}", $b->department?->name ?? "\u{FFFF}"),
                fn (Employee $a, Employee $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /** Whether the employee was employed on $date. */
    public static function employedOn(Employee $k, CarbonImmutable $date): bool
    {
        $hired = $k->profile?->hire_date;
        if ($hired && $date->lt($hired)) {
            return false;
        }

        return ! (($exit = self::exitDate($k)) && $date->gte($exit));
    }

    /** The first day an employee who left no longer works (their exit date), or null. */
    public static function exitDate(Employee $k): ?CarbonImmutable
    {
        return $k->status === Employee::STATUS_TERMINATED && $k->termination_date ? CarbonImmutable::parse($k->termination_date->toDateString()) : null;
    }

    /**
     * A check of whether an employee worked on a day: not before joining, not from the exit date on.
     *
     * @param  iterable<int>  $karyawanIds
     * @return \Closure(int, string): bool karyawan id, Y-m-d
     */
    public static function workingDays(iterable $employeeIds): \Closure
    {
        $spans = Employee::with('profile:id,employee_id,hire_date')->whereIn('id', collect($employeeIds)->all())->get()
            ->mapWithKeys(fn (Employee $k) => [$k->id => [$k->profile?->hire_date?->toDateString(), self::exitDate($k)?->toDateString()]]);

        return function (int $id, string $date) use ($spans): bool {
            [$hired, $exit] = $spans[$id] ?? [null, null];

            return (! $hired || $date >= $hired) && (! $exit || $date < $exit);
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
