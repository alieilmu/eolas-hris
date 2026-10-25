<?php

namespace App\Support\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\ShiftSchedule;

/**
 * Potong gapok: days in Absensi whose code cuts the gaji pokok (ALPA, Izin Potong Gapok —
 * Pengaturan Waktu & Kehadiran → Kode Shift) cost gaji pokok ÷ divisor each. The divisor is
 * the working days in the employee's roster for the period, or a fixed number (Pengaturan
 * Kompensasi → Payroll); a roster with no working days falls back to the fixed number.
 */
class AbsenceDeduction
{
    /**
     * @return array{days: int, divisor: int, divisor_source: string, amount: int, dates: list<array{date: string, code: string}>}
     */
    public static function for(Employee $k, PayrollPeriod $p, PayrollSetting $s, int $baseSalary): array
    {
        $records = AttendanceRecord::where('employee_id', $k->id)
            ->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])
            ->whereHas('shiftCode', fn ($q) => $q->where('deducts_base_salary', true))
            ->with('shiftCode:id,code')->orderBy('date')->get();

        $divisor = $s->absence_divisor_days;
        $source = 'fixed';
        if ($s->absence_divisor_mode === 'schedule') {
            $workDays = ShiftSchedule::where('employee_id', $k->id)
                ->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])
                ->whereHas('shiftCode', fn ($q) => $q->where('type', 'work'))->count();
            if ($workDays > 0) {
                [$divisor, $source] = [$workDays, 'schedule'];
            }
        }
        $days = $records->count();
        $divisor = max(1, $divisor);

        return [
            'days' => $days,
            'divisor' => $divisor,
            'divisor_source' => $source,
            'amount' => $days ? min($baseSalary, (int) round($baseSalary * $days / $divisor)) : 0,
            'dates' => $records->map(fn (AttendanceRecord $a) => ['date' => $a->date->toDateString(), 'code' => $a->shiftCode->code])->all(),
        ];
    }
}
