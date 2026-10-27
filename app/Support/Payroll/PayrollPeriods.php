<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;

/** Whether an employee's pay for a period is settled already (an approved regular run covers them). */
class PayrollPeriods
{
    public static function closed(Employee $k, string $period): bool
    {
        return PayrollRun::where('type', 'regular')->where('period', $period)->whereIn('status', PayrollRun::FINAL)
            ->where(fn ($q) => $q->whereNull('work_location_id')->orWhere('work_location_id', $k->work_location_id))
            ->exists();
    }
}
