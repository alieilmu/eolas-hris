<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's pay in a payroll run, as it was worked out then (snapshot + detail). */
#[Fillable([
    'payroll_run_id', 'employee_id', 'work_location_id', 'employee_number', 'name', 'snapshot', 'wage', 'gross', 'total_earnings', 'total_deductions',
    'tax', 'tax_withheld', 'employee_contributions', 'company_cost', 'take_home_pay', 'prorate', 'error', 'warnings', 'detail',
])]
class Payslip extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'warnings' => 'array', 'detail' => 'array', 'prorate' => 'float'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort');
    }
}
