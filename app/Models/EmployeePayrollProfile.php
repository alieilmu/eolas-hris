<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's payroll choices: tax method (null = the default) and BPJS participation. */
#[Fillable(['employee_id', 'tax_method', 'bpjs_health', 'bpjs_employment'])]
class EmployeePayrollProfile extends Model
{
    protected function casts(): array
    {
        return ['bpjs_health' => 'boolean', 'bpjs_employment' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
