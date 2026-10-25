<?php

namespace App\Support\TimeClock;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\ShiftCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes Kehadiran into Absensi and Lembur, for days before today:
 *  - Absensi: a day with clock-ins becomes its roster code (or, on a day off, the work
 *    shift whose start is nearest to CI); a scheduled day without any becomes Mangkir;
 *    an unworked day off stays Off. Days set by hand (manual / import) or by a trip are
 *    left alone; the others are marked sumber "mesin".
 *  - Lembur: overtime of employees entitled to it, waiting for approval. A day already
 *    approved or rejected is kept; a waiting one without overtime any more is removed.
 */
class AttendanceProcessor
{
    private const DIBIARKAN = ['manual', 'import', 'leave_travel'];

    /** @return array{absensi: int, lembur: int} */
    public static function run(CarbonImmutable $from, CarbonImmutable $until, ?array $employeeIds = null): array
    {
        $until = $until->min(CarbonImmutable::yesterday());
        if ($until->lt($from)) {
            return ['attendance' => 0, 'overtime' => 0];
        }
        $employees = Employee::withoutGlobalScope('access')->with(['workLocation', 'jobLevel', 'profile'])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->where(fn ($q) => $q->where('status', Employee::STATUS_ACTIVE)->orWhere('termination_date', '>=', $from->toDateString()))
            ->get();
        $codes = ShiftCode::orderBy('sort_order')->orderBy('id')->get();
        $absent = $codes->firstWhere('type', 'absent');
        $work = $codes->filter(fn (ShiftCode $c) => $c->type === 'work');
        $byKode = $codes->keyBy('code');
        $now = now();
        $attendance = 0;
        $lemburCount = 0;

        foreach ($employees->chunk(50) as $chunk) {
            $rows = AttendanceSummary::build($chunk, $from, $until);
            $existing = DB::table('attendance_records')->whereIn('employee_id', $chunk->pluck('id'))
                ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
                ->get(['employee_id', 'date', 'source'])->keyBy(fn ($r) => $r->employee_id.'|'.substr($r->date, 0, 10));
            $overtime_records = OvertimeRecord::whereIn('employee_id', $chunk->pluck('id'))
                ->whereBetween('date', [$from->toDateString(), $until->toDateString()])->get()
                ->keyBy(fn (OvertimeRecord $l) => $l->employee_id.'|'.$l->date->toDateString());

            $write = [];
            foreach ($rows as $r) {
                $key = $r['employee_id'].'|'.$r['date'];
                $source = $existing[$key]->source ?? null;
                if (! in_array($source, self::DIBIARKAN, true) && ! in_array($r['status'], ['business_trip', 'leave', 'sick', 'permission'], true)) {
                    $code = match ($r['status']) {
                        'complete', 'missing_clock_out', 'missing_clock_in' => $byKode[$r['schedule']] ?? null,
                        'mismatch' => $r['schedule_type'] === 'work' ? ($byKode[$r['schedule']] ?? null) : self::nearest($work, $r['ci_at'] ?? $r['co_at']),
                        'no_show' => $absent,
                        'off' => $byKode[$r['schedule']] ?? null,
                        default => null,
                    };
                    if ($code) {
                        $write[] = ['employee_id' => $r['employee_id'], 'date' => $r['date'], 'shift_code_id' => $code->id,
                            'source' => $r['status'] === 'off' ? 'schedule' : 'device', 'leave_travel_request_id' => null, 'created_at' => $now, 'updated_at' => $now];
                    }
                }

                $l = $overtime_records[$key] ?? null;
                $ot = $r['is_overtime_eligible'] ? $r['overtime'] : null;
                if ($l && $l->status !== 'pending') {
                    continue; // decided: kept as it is
                }
                if (! $ot || $ot['overtime_minutes'] <= 0) {
                    if ($l && $l->source === 'device') {
                        $l->delete();
                    }

                    continue;
                }
                OvertimeRecord::updateOrCreate(['employee_id' => $r['employee_id'], 'date' => $r['date']], [
                    'day_type' => $r['day_type'], 'start_date' => $r['ci_at'], 'end_date' => $r['co_at'],
                    'worked_minutes' => $r['worked_minutes'], 'overtime_minutes' => $ot['overtime_minutes'],
                    'paid_hours' => $ot['paid_hours'], 'weighted_hours' => $ot['weighted_hours'],
                    'status' => 'pending', 'source' => 'device',
                ]);
                $lemburCount++;
            }
            foreach (array_chunk($write, 500) as $part) {
                DB::table('attendance_records')->upsert($part, ['employee_id', 'date'], ['shift_code_id', 'source', 'leave_travel_request_id', 'updated_at']);
            }
            $attendance += count($write);
        }

        return ['attendance' => $attendance, 'overtime' => $lemburCount];
    }

    /** The work shift whose start is nearest to $at (time of day), for a day off worked. */
    private static function nearest($work, ?string $at): ?ShiftCode
    {
        if (! $at) {
            return $work->first();
        }
        $t = CarbonImmutable::parse($at);
        $minutes = $t->hour * 60 + $t->minute;

        return $work->filter(fn (ShiftCode $c) => $c->scheduled())
            ->sortBy(function (ShiftCode $c) use ($minutes) {
                [$h, $m] = array_map('intval', explode(':', $c->start_time));
                $diff = abs($h * 60 + $m - $minutes);

                return min($diff, 1440 - $diff);
            })->first() ?? $work->first();
    }
}
