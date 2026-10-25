<?php

namespace App\Models;

use App\Support\Employees\EmployeeNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NRP digits 2–3: where the employee was hired (e.g. 15 Murung Raya, 03 Rantau, 01/11 Head Office). */
#[Fillable(['code', 'name', 'work_location_id'])]
class PointOfHire extends Model
{
    protected $table = 'points_of_hire';

    protected static function booted(): void
    {
        static::saved(fn () => EmployeeNumber::flush());
        static::deleted(fn () => EmployeeNumber::flush());
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** Employees whose NRP carries this code in digits 2–3. */
    public function employeeCount(): int
    {
        return Employee::where('employee_number', 'like', "_{$this->code}%")->count();
    }
}
