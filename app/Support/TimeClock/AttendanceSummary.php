<?php

namespace App\Support\TimeClock;

use App\Models\AttendanceLog;
use App\Models\AttendanceRecord;
use App\Models\PublicHoliday;
use App\Models\Employee;
use App\Models\OvertimePolicy;
use App\Models\ShiftSchedule;
use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kehadiran per employee per day, from the machines' clock in / clock out (CI/CO) set
 * against the Roster:
 *
 *  1. Every scheduled work day (a roster code with hours) takes the logs in its window,
 *     from 4 hours before the start to 6 hours after the end (never into the next or
 *     previous scheduled shift): the earliest is CI, the latest CO. A single log is CI
 *     or CO by whichever end of the shift it is closer to. A night shift's CO is on the
 *     next calendar day and still belongs to the night it started.
 *  2. Days without a schedule (OFF, no roster) take the logs left over between 04:00
 *     that day and 04:00 the next: working on them does not match the roster.
 *
 * Effective working time runs from CI (or the scheduled start, when work before it does
 * not count) to CO, minus the break; on a day without a schedule an hour is taken off
 * when the span is over 5 hours. Overtime follows LemburHitung.
 */
class AttendanceSummary
{
    public const STATUS = [
        'complete' => 'Lengkap',
        'missing_clock_out' => 'Tidak ada CO',
        'missing_clock_in' => 'Tidak ada CI',
        'no_show' => 'Tidak hadir',
        'mismatch' => 'Tidak sesuai roster',
        'off' => 'Off',
        'without_schedule' => 'Tanpa roster',
        'business_trip' => 'Dinas',
        'leave' => 'Cuti',
        'sick' => 'Sakit',
        'permission' => 'Izin',
    ];

    /** Statuses that need HR's attention. */
    public const MASALAH = ['missing_clock_out', 'missing_clock_in', 'no_show', 'mismatch'];

    private const SEBELUM_MENIT = 240;

    private const SESUDAH_MENIT = 360;

    /** CI this far from the scheduled start means another shift was worked. */
    private const BEDA_SHIFT_MENIT = 180;

    /**
     * @param  Collection<int, Karyawan>  $karyawans  with workLocation and subGroup
     * @return list<array<string, mixed>>
     */
    public static function build(Collection $employees, CarbonImmutable $dari, CarbonImmutable $until): array
    {
        if ($employees->isEmpty()) {
            return [];
        }
        $ids = $employees->pluck('id')->all();
        $from = $dari->subDay();
        $to = $until->addDay();
        $codes = ShiftCode::all()->keyBy('id');
        $policy = OvertimePolicy::current();
        $public_holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('name', 'date')
            ->mapWithKeys(fn ($name, $tgl) => [CarbonImmutable::parse($tgl)->toDateString() => $name]);
        $eligibility = DB::table('overtime_eligibilities')->get()->mapWithKeys(fn ($r) => ["{$r->job_level_id}|{$r->location_type}" => (bool) $r->is_eligible]);

        $shift_schedules = [];
        foreach (ShiftSchedule::whereIn('employee_id', $ids)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get(['employee_id', 'date', 'shift_code_id']) as $r) {
            $shift_schedules[$r->employee_id][$r->date->toDateString()] = $codes[$r->shift_code_id] ?? null;
        }
        $attendance = [];
        foreach (AttendanceRecord::whereIn('employee_id', $ids)->whereBetween('date', [$dari->toDateString(), $until->toDateString()])->get(['employee_id', 'date', 'shift_code_id', 'source']) as $a) {
            $attendance[$a->employee_id][$a->date->toDateString()] = ['code' => $codes[$a->shift_code_id] ?? null, 'source' => $a->source];
        }
        $leave_travel_requests = LeaveTravelRequest::whereIn('employee_id', $ids)->where('status', 'approved')
            ->where('start_date', '<=', $until->toDateString())->where('end_date', '>=', $dari->toDateString())
            ->get(['employee_id', 'type', 'start_date', 'end_date'])->groupBy('employee_id');
        $logs = AttendanceLog::whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$from->toDateString().' 00:00:00', $to->addDay()->toDateString().' 12:00:00'])
            ->orderBy('punched_at')->get(['id', 'employee_id', 'punched_at', 'source'])->groupBy('employee_id');

        $out = [];
        foreach ($employees as $k) {
            $tipe = $k->workLocation?->type === WorkLocation::TYPE_HEAD_OFFICE ? WorkLocation::TYPE_HEAD_OFFICE : WorkLocation::TYPE_SITE;
            $ctx = [
                'scheme' => $k->workLocation?->work_schedule_scheme ?? 'five_day',
                'normal_hours' => (float) ($k->workLocation?->daily_normal_hours ?? 8),
                'is_eligible' => $eligibility["{$k->job_level_id}|{$tipe}"] ?? true,
            ];
            $days = self::assign($shift_schedules[$k->id] ?? [], ($logs[$k->id] ?? collect())->all(), $from, $to);
            for ($d = $dari; $d->lte($until); $d = $d->addDay()) {
                $key = $d->toDateString();
                $leave_travel = ($leave_travel_requests[$k->id] ?? collect())->first(fn ($t) => $t->start_date->lte($d) && $t->end_date->gte($d));
                $out[] = self::day($k, $d, $shift_schedules[$k->id][$key] ?? null, $days[$key] ?? ['logs' => [], 'shift_window' => null],
                    $attendance[$k->id][$key] ?? null, $leave_travel, isset($public_holidays[$key]), $ctx, $policy);
            }
        }

        return $out;
    }

    /**
     * Gives each day its logs (step 1 and 2 of the class comment).
     *
     * @param  array<string, ?ShiftCode>  $roster  date => code
     * @param  list<AbsenLog>  $logs  in time order
     * @return array<string, array{logs: list<AbsenLog>, jadwal: ?Jadwal}>
     */
    private static function assign(array $schedule, array $logs, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $shift_window = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $code = $schedule[$d->toDateString()] ?? null;
            if ($code && $code->type === 'work' && ($j = ShiftWindow::of($code, $d))) {
                $shift_window[$d->toDateString()] = $j;
            }
        }
        // Logs and windows are both in time order, so one pointer walks the logs (as plain
        // timestamps): linear instead of every log against every day.
        $at = array_map(fn ($log) => $log->punched_at->getTimestamp(), $logs);
        $count = count($logs);
        $used = [];
        $days = [];
        // Windows of one pass only move forward: logs before a window's start are never in a later one.
        $first = 0;
        $take = function (int $start, int $end, bool $endInclusive) use (&$used, &$first, $at, $logs, $count): array {
            while ($first < $count && $at[$first] < $start) {
                $first++;
            }
            $mine = [];
            for ($n = $first; $n < $count && $at[$n] <= $end; $n++) {
                if (! isset($used[$n]) && $at[$n] >= $start && ($endInclusive || $at[$n] < $end)) {
                    $mine[] = $logs[$n];
                    $used[$n] = true;
                }
            }

            return $mine;
        };
        $keys = array_keys($shift_window);
        foreach ($keys as $i => $key) {
            $j = $shift_window[$key];
            $start = $j->start->subMinutes(self::SEBELUM_MENIT);
            $end = $j->end->addMinutes(self::SESUDAH_MENIT);
            if ($i > 0) {
                $start = $start->max($shift_window[$keys[$i - 1]]->end->addHour());
            }
            if (isset($keys[$i + 1])) {
                $end = $end->min($shift_window[$keys[$i + 1]]->start->subHours(2));
            }
            $days[$key] = ['logs' => $take($start->getTimestamp(), $end->getTimestamp(), true), 'shift_window' => $j];
        }
        // Days without a schedule: the logs left over, 04:00 to 04:00.
        $first = 0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            if (isset($shift_window[$key])) {
                continue;
            }
            $start = $d->setTime(4, 0);
            $days[$key] = ['logs' => $take($start->getTimestamp(), $start->addDay()->getTimestamp(), false), 'shift_window' => null];
        }

        return $days;
    }

    /** @param array{logs: list<AbsenLog>, jadwal: ?Jadwal} $slot */
    private static function day(Employee $k, CarbonImmutable $d, ?ShiftCode $schedule, array $slot, ?array $attendance, ?LeaveTravelRequest $leave_travel, bool $public_holiday, array $ctx, OvertimePolicy $policy): array
    {
        $j = $slot['shift_window'];
        $logs = $slot['logs'];
        [$ci, $co] = [null, null];
        if ($logs) {
            $first = $logs[0]->punched_at;
            $last = end($logs)->punched_at;
            if (count($logs) > 1 && $first->diffInMinutes($last) >= 30) {
                [$ci, $co] = [$first, $last];
            } elseif ($j && abs($first->diffInMinutes($j->end)) < abs($first->diffInMinutes($j->start))) {
                $co = $first;
            } else {
                $ci = $first;
            }
        }

        $absensiJenis = ($attendance['code'] ?? null)?->type;
        $status = match (true) {
            $leave_travel !== null => $leave_travel->type,
            in_array($attendance['source'] ?? null, ['manual', 'import'], true) && in_array($absensiJenis, ['sick', 'permission', 'leave'], true) && ! $logs => $absensiJenis,
            $schedule === null => $logs ? 'mismatch' : 'without_schedule',
            $schedule->type !== 'work' => $logs ? 'mismatch' : 'off',
            $ci && $co => $j && abs($j->start->diffInMinutes($ci)) > self::BEDA_SHIFT_MENIT ? 'mismatch' : 'complete',
            $ci !== null => 'missing_clock_out',
            $co !== null => 'missing_clock_in',
            default => 'no_show',
        };

        $workedMinutes = 0;
        if ($ci && $co) {
            $start = $j && ! $policy->count_before_shift ? $ci->max($j->start) : $ci;
            $span = $start->lt($co) ? (int) $start->diffInMinutes($co) : 0;
            $workedMinutes = $j ? $span - $j->breakWithin($start, $co) : ($span > 300 ? $span - 60 : $span);
        }
        $jenisHari = $public_holiday ? 'public_holiday' : ($j ? 'workday' : 'rest_day');
        $overtime = $workedMinutes > 0 && ! in_array($status, ['business_trip', 'leave'], true)
            ? OvertimeCalculator::calculate($jenisHari, max(0, $workedMinutes), $ctx['scheme'], $ctx['normal_hours'], $policy)
            : null;

        $fmt = fn (?CarbonImmutable $t) => $t ? ($t->isSameDay($d) ? $t->format('H:i') : $t->format('d/m H:i')) : null;

        return [
            'employee_id' => $k->id,
            'employee_number' => $k->employee_number,
            'name' => $k->name,
            'location' => $k->workLocation?->name,
            'date' => $d->toDateString(),
            'schedule' => $schedule?->code,
            'schedule_type' => $schedule?->type,
            'shift_window' => $j ? $j->start->format('H:i').'–'.$j->end->format('H:i') : null,
            'ci' => $fmt($ci),
            'co' => $fmt($co),
            'ci_at' => $ci?->toDateTimeString(),
            'co_at' => $co?->toDateTimeString(),
            'log_count' => count($logs),
            'correction' => collect($logs)->contains(fn (AttendanceLog $l) => $l->source === 'manual'),
            'status' => $status,
            'late' => $j && $ci && $ci->gt($j->start) ? (int) $j->start->diffInMinutes($ci) : 0,
            'early_leave' => $j && $co && $co->lt($j->end) ? (int) $co->diffInMinutes($j->end) : 0,
            'worked_minutes' => max(0, $workedMinutes),
            'day_type' => $jenisHari,
            'public_holiday' => $public_holiday,
            'is_overtime_eligible' => $ctx['is_eligible'],
            'overtime' => $overtime,
            'attendance' => ($attendance['code'] ?? null)?->code,
            'attendance_source' => $attendance['source'] ?? null,
        ];
    }
}
