<?php

namespace App\Support\Time;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShiftSchedule;
use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Man Onsite: where each employee is on each day. A Cuti & Dinas entry wins
 * (dinas / cuti); otherwise Absensi says it, and where Absensi is still empty
 * (e.g. days ahead) the Roster stands in as the plan.
 */
class Onsite
{
    /** How a Kode Shift's jenis is counted. */
    private const STATUS = [
        'work' => 'onsite',
        'business_trip' => 'business_trip',
        'leave' => 'leave',
        'off' => 'off',
        'sick' => 'attendance',
        'permission' => 'attendance',
        'absent' => 'attendance',
    ];

    public const KEYS = ['onsite', 'business_trip', 'leave', 'off', 'attendance', 'blank'];

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
            $k->profile?->hire_date?->toDateString() ?? '0000-00-00',
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
                [$status, $plan] = $map[$id][$key] ?? ['blank', false];
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
        $type = ShiftCode::pluck('type', 'id');
        $range = [$from->toDateString(), $to->toDateString()];

        $map = [];
        foreach (ShiftSchedule::whereBetween('date', $range)->whereIn('employee_id', $ids)->get(['employee_id', 'date', 'shift_code_id']) as $r) {
            $map[$r->employee_id][$r->date->toDateString()] = [self::STATUS[$type[$r->shift_code_id]] ?? 'blank', true];
        }
        foreach (AttendanceRecord::whereBetween('date', $range)->whereIn('employee_id', $ids)->get(['employee_id', 'date', 'shift_code_id']) as $a) {
            $map[$a->employee_id][$a->date->toDateString()] = [self::STATUS[$type[$a->shift_code_id]] ?? 'blank', false];
        }
        foreach (LeaveTravelRequest::approved()->overlapping($from, $to)->whereIn('employee_id', $ids)->get() as $t) {
            $start = $t->start_date->max($from);
            $end = $t->end_date->min($to);
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                $map[$t->employee_id][$d->toDateString()] = [$t->type, false];
            }
        }

        return $map;
    }

    /** People away today (dinas / cuti), with where they went and until when. */
    public static function awayToday(?int $workLocationId, CarbonImmutable $today, int|string|null $subLocation = null): array
    {
        return LeaveTravelRequest::with(['employee.position:id,name', 'employee.workLocation:id,name'])
            ->approved()
            ->overlapping($today, $today)
            ->when($workLocationId, fn ($q) => $q->whereHas('employee', fn ($k) => $k->where('work_location_id', $workLocationId)))
            ->when($subLocation !== null, fn ($q) => $q->whereHas('employee', fn ($k) => Roll::whereSubLocation($k, $subLocation)))
            ->orderBy('end_date')
            ->get()
            ->map(fn (LeaveTravelRequest $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'employee_number' => $t->employee->employee_number,
                'name' => $t->employee->name,
                'position' => $t->employee->position?->name,
                'work_location' => $t->employee->workLocation?->name,
                'destination_city' => $t->destination_city,
                'start_date' => $t->start_date->toDateString(),
                'end_date' => $t->end_date->toDateString(),
                'days_left' => (int) $today->diffInDays($t->end_date),
            ])
            ->all();
    }

    /** The employee's status today, by their id (for the trip form). */
    public static function todayOf(Employee $k, CarbonImmutable $today): string
    {
        return self::statusMap(collect([$k]), $today, $today)[$k->id][$today->toDateString()][0] ?? 'blank';
    }
}
