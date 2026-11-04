<?php

namespace App\Support\TimeClock;

use App\Models\Employee;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Workflow\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Lembur from the portal: overtime the machines could not count (no machine at the
 * place, work after the last punch), asked for by the employee with its hours and the reason.
 * Counted as the processing counts it (day type from the roster and the holidays, the
 * location's scheme, the rounding and minimum of the policy), at most the daily limit, and
 * approved through the overtime flow. Reprocessing the day leaves it as it was asked.
 */
class OvertimeRequest
{
    /** How many days back an employee may still ask. */
    public const DAYS_BACK = 7;

    /** Whether this employee's job level gets overtime at their kind of location. */
    public static function eligible(Employee $employee): bool
    {
        $type = $employee->workLocation?->type === WorkLocation::TYPE_HEAD_OFFICE ? WorkLocation::TYPE_HEAD_OFFICE : WorkLocation::TYPE_SITE;
        $row = DB::table('overtime_eligibilities')->where('job_level_id', $employee->job_level_id)->where('location_type', $type)->first();

        return $row ? (bool) $row->is_eligible : true;
    }

    /** For the form: whether it shows, how far back, the limits. */
    public static function form(Employee $employee): array
    {
        $policy = OvertimePolicy::current();

        return [
            'eligible' => self::eligible($employee),
            'earliest' => CarbonImmutable::today()->subDays(self::DAYS_BACK)->toDateString(),
            'minimum_minutes' => (int) $policy->minimum_minutes,
            'daily_limit_hours' => (int) $policy->daily_limit_hours,
        ];
    }

    /**
     * @param  array{date: string, start: string, end: string, reason: string}  $data  times as HH:MM; an end before the start is the next day
     *
     * @throws ValidationException
     */
    public static function submit(Employee $employee, User $user, array $data): OvertimeRecord
    {
        if (! self::eligible($employee)) {
            throw ValidationException::withMessages(['date' => 'Jabatan Anda tidak mendapat lembur. Hubungi HR bila ini keliru.']);
        }
        $date = CarbonImmutable::parse($data['date'])->startOfDay();
        $today = CarbonImmutable::today();
        if ($date->gt($today) || $date->lt($today->subDays(self::DAYS_BACK))) {
            throw ValidationException::withMessages(['date' => 'Lembur diajukan untuk hari ini atau paling lama '.self::DAYS_BACK.' hari ke belakang.']);
        }
        $start = CarbonImmutable::parse("{$date->toDateString()} {$data['start']}");
        $end = CarbonImmutable::parse("{$date->toDateString()} {$data['end']}");
        if ($end->lte($start)) {
            $end = $end->addDay();
        }
        if ($end->gt(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['end' => 'Lembur diajukan setelah selesai dikerjakan.']);
        }

        $existing = OvertimeRecord::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        if ($existing) {
            throw ValidationException::withMessages(['date' => match (true) {
                $existing->source === 'portal' && $existing->status === 'pending' => 'Anda sudah mengajukan lembur untuk tanggal ini.',
                $existing->status === 'pending' => "Lembur tanggal ini sudah terhitung dari absen ({$existing->summary()}) dan menunggu persetujuan.",
                default => 'Lembur tanggal ini sudah '.(OvertimeRecord::STATUS[$existing->status] ?? $existing->status).'.',
            }]);
        }

        $policy = OvertimePolicy::current();
        $minutes = (int) $start->diffInMinutes($end);
        $dayType = PublicHoliday::whereDate('date', $date)->exists() ? 'public_holiday'
            : (ShiftSchedule::with('shiftCode')->where('employee_id', $employee->id)->whereDate('date', $date)->first()?->shiftCode?->type === 'work' ? 'workday' : 'rest_day');
        $normal = (float) ($employee->workLocation?->daily_normal_hours ?? 8);
        // The times asked for are the overtime itself: on a work day the normal hours come before them.
        $calc = OvertimeCalculator::calculate($dayType, $dayType === 'workday' ? $minutes + (int) round($normal * 60) : $minutes,
            $employee->workLocation?->work_schedule_scheme ?? 'five_day', $normal, $policy);
        if ($calc['overtime_minutes'] <= 0) {
            throw ValidationException::withMessages(['end' => "Kurang dari lembur minimum ({$policy->minimum_minutes} menit)."]);
        }
        if ($calc['paid_hours'] > $policy->daily_limit_hours) {
            throw ValidationException::withMessages(['end' => "Lembur paling lama {$policy->daily_limit_hours} jam sehari."]);
        }

        return DB::transaction(function () use ($employee, $user, $data, $date, $start, $end, $minutes, $dayType, $calc) {
            $record = OvertimeRecord::create([
                'employee_id' => $employee->id, 'date' => $date->toDateString(), 'day_type' => $dayType,
                'start_date' => $start, 'end_date' => $end, 'worked_minutes' => $minutes,
                'overtime_minutes' => $calc['overtime_minutes'], 'paid_hours' => $calc['paid_hours'], 'weighted_hours' => $calc['weighted_hours'],
                'status' => 'pending', 'source' => 'portal', 'notes' => $data['reason'],
            ]);
            Workflow::start($record, 'overtime', $employee, $user, $record->workflowFacts(), $record->summary().' · '.$data['reason'], $record->workflowNotice()['url']);

            return $record;
        });
    }
}
