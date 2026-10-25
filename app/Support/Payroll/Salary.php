<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\SalaryScalePeriod;
use App\Models\SalaryScaleAmount;
use App\Models\JobLevelAllowance;
use Carbon\CarbonImmutable;

/**
 * An employee's fixed pay: Gaji Pokok from the Struktur Skala Upah (the golongan + step of
 * their latest KaryawanGaji up to a date, in the scale column of their work location and
 * year), and Tunjangan Jabatan of their SubGroup. Upah tetap = both together, the basis of
 * BPJS, THR and the hourly overtime wage (1/173).
 */
class Salary
{
    /** @var array<string, ?int> */
    private static array $amount = [];

    /** @var array<int, int>|null sub_group_id => nominal */
    private static ?array $allowance = null;

    public static function flush(): void
    {
        self::$amount = [];
        self::$allowance = null;
    }

    /** The KaryawanGaji in force on $on (the latest one starting on or before it). */
    public static function current(Employee $k, ?CarbonImmutable $on = null): ?EmployeeSalary
    {
        $on ??= CarbonImmutable::today();
        $rows = $k->relationLoaded('salaries') ? $k->salaries : $k->salaries()->with('salaryScale.grade')->get();

        return $rows->first(fn (EmployeeSalary $g) => $g->effective_date->lte($on));
    }

    public static function base(Employee $k, ?CarbonImmutable $on = null): ?int
    {
        $on ??= CarbonImmutable::today();
        $position_title = self::current($k, $on);

        return $position_title ? self::amount($position_title->salary_scale_id, $k->work_location_id, $on->year) : null;
    }

    /** A scale line's wage: the latest column up to $tahun for the location, else the general one. */
    public static function amount(int $skalaUpahId, ?int $workLocationId, int $year): ?int
    {
        $key = "{$skalaUpahId}:{$workLocationId}:{$year}";
        if (array_key_exists($key, self::$amount)) {
            return self::$amount[$key];
        }
        $kolomIds = SalaryScalePeriod::where('year', '<=', $year)
            ->where(fn ($q) => $q->whereNull('work_location_id')->when($workLocationId, fn ($q) => $q->orWhere('work_location_id', $workLocationId)))
            ->orderByDesc('year')->orderByRaw('work_location_id is null')
            ->pluck('id');
        $value = SalaryScaleAmount::where('salary_scale_id', $skalaUpahId)->whereIn('salary_scale_period_id', $kolomIds)->pluck('amount', 'salary_scale_period_id');
        $found = null;
        foreach ($kolomIds as $id) {
            if (isset($value[$id])) {
                $found = (int) $value[$id];
                break;
            }
        }

        return self::$amount[$key] = $found;
    }

    public static function positionAllowance(Employee $k): int
    {
        self::$allowance ??= JobLevelAllowance::pluck('amount', 'job_level_id')->map(fn ($n) => (int) $n)->all();

        return $k->job_level_id ? (self::$allowance[$k->job_level_id] ?? 0) : 0;
    }

    /** Gaji pokok + tunjangan tetap; null when the gaji pokok is not known. */
    public static function fixedPay(Employee $k, ?CarbonImmutable $on = null): ?int
    {
        $base = self::base($k, $on);

        return $base === null ? null : $base + self::positionAllowance($k);
    }
}
