<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's payroll choices: tax method (null = the default), BPJS participation and the
 * extra family members on their BPJS Kesehatan (beyond spouse and three children, 1% each).
 */
#[Fillable(['employee_id', 'tax_method', 'bpjs_health', 'bpjs_employment', 'health_extra_members'])]
class EmployeePayrollProfile extends Model
{
    protected function casts(): array
    {
        return ['bpjs_health' => 'boolean', 'bpjs_employment' => 'boolean', 'health_extra_members' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
