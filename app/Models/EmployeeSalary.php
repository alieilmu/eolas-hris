<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's place in the Struktur Skala Upah (golongan + step) from a date on. The
 * amount itself is read from the scale for the employee's work location and the year
 * (App\Support\Payroll\Gaji), so a new scale column raises everyone on it.
 */
#[Fillable(['employee_id', 'salary_scale_id', 'effective_date', 'reason', 'notes', 'created_by'])]
class EmployeeSalary extends Model
{
    public const ALASAN = ['initial' => 'Gaji awal', 'step_increase' => 'Kenaikan step', 'promotions' => 'Promosi / naik golongan', 'adjustment' => 'Penyesuaian'];

    protected function casts(): array
    {
        return ['effective_date' => 'immutable_date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }
}
