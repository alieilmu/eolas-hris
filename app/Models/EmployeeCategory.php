<?php

namespace App\Models;

use App\Support\Employees\EmployeeNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** First NRP digit: 1 Karyawan Normal, 7 Tenaga Ahli, 8 Mekanik Magang, 9 Operator Magang, ... */
#[Fillable(['code', 'name'])]
class EmployeeCategory extends Model
{
    protected $table = 'employee_categories';

    protected static function booted(): void
    {
        static::saved(fn () => EmployeeNumber::flush());
        static::deleted(fn () => EmployeeNumber::flush());
    }

    /** Employees whose NRP starts with this code. */
    public function employeeCount(): int
    {
        return Employee::where('employee_number', 'like', "{$this->code}%")->count();
    }
}
