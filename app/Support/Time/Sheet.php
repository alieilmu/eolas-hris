<?php

namespace App\Support\Time;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShiftSchedule;
use App\Models\ShiftCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly table of Roster (the plan) or Absensi (what happened): one row per
 * employee, one cell per day holding a Kode Shift.
 */
class Sheet
{
    public const TYPES = ['schedule', 'attendance'];

    public static function model(string $type): string
    {
        return $type === 'schedule' ? ShiftSchedule::class : AttendanceRecord::class;
    }

    /** Kode Shift in display order, for the page. */
    public static function codes(): array
    {
        return ShiftCode::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ShiftCode $c) => ['id' => $c->id, 'code' => $c->code, 'daily_work_schedule' => $c->daily_work_schedule, 'description' => $c->description, 'type' => $c->type])
            ->all();
    }

    /**
     * @return Collection<int, array<int, int>> karyawan id => [day => shift_code_id]
     */
    public static function cells(string $type, Period $period, ?Collection $employeeIds = null): Collection
    {
        return self::model($type)::query()
            ->whereBetween('date', [$period->start->toDateString(), $period->end()->toDateString()])
            ->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get(['employee_id', 'date', 'shift_code_id'])
            ->groupBy('employee_id')
            ->map(fn (Collection $days) => $days->mapWithKeys(fn ($d) => [$d->date->day => $d->shift_code_id])->all());
    }

    /** Every row of the month's table. */
    public static function rows(string $type, Period $period): array
    {
        $people = Roll::between($period->start, $period->end());
        $ids = $people->pluck('id');
        $cells = self::cells($type, $period, $ids);
        // Absensi is compared against the roster, and shows which days came from a trip.
        $plan = $type === 'attendance' ? self::cells('schedule', $period, $ids) : collect();
        $tripDays = $type === 'attendance'
            ? AttendanceRecord::whereBetween('date', [$period->start->toDateString(), $period->end()->toDateString()])
                ->whereIn('employee_id', $ids)->whereNotNull('leave_travel_request_id')
                ->get(['employee_id', 'date'])
                ->groupBy('employee_id')
                ->map(fn (Collection $d) => $d->map(fn ($a) => $a->date->day)->values()->all())
            : collect();

        return $people->map(function (Employee $k) use ($type, $period, $cells, $plan, $tripDays) {
            [$from, $until] = self::employedDays($k, $period);
            // Days outside employment are shown blocked, never with codes.
            $within = fn (array $days) => array_filter($days, fn ($day) => $day >= $from && $day <= $until, ARRAY_FILTER_USE_KEY);
            $exit = Roll::exitDate($k);

            return [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'status' => $k->status,
                'position' => $k->position?->name,
                'department' => $k->department?->name,
                'department_id' => $k->department_id,
                'work_location' => $k->workLocation?->name,
                'work_location_id' => $k->work_location_id,
                'sub_location' => $k->subLocation?->name,
                'sub_location_id' => $k->sub_location_id,
                'from' => $from,
                'until' => $until,
                // From this day the row is blocked, labelled with the exit reason (Resign, Mutasi, ...).
                'termination' => $exit && $period->contains($exit) ? ['date' => $exit->toDateString(), 'reason' => $k->termination_reason ?: 'Keluar'] : null,
                'cells' => (object) $within($cells[$k->id] ?? []),
                ...($type === 'attendance' ? [
                    'plan' => (object) $within($plan[$k->id] ?? []),
                    'trip_days' => array_values(array_filter($tripDays[$k->id] ?? [], fn ($day) => $day >= $from && $day <= $until)),
                ] : []),
            ];
        })->all();
    }

    /** The first and last day of the month the employee was employed (the exit day is no longer one). */
    public static function employedDays(Employee $k, Period $period): array
    {
        $from = 1;
        $until = $period->dayCount();
        $hired = $k->profile?->hire_date;
        if ($hired && $period->contains(CarbonImmutable::parse($hired))) {
            $from = $hired->day;
        }
        $exit = Roll::exitDate($k);
        if ($exit && $period->contains($exit)) {
            $until = $exit->day - 1;
        }

        return [$from, $until];
    }
}
