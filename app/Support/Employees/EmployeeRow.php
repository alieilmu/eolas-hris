<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Support\Compensation\BenefitEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Collapses an employee plus profile and child tables into one flat row, keyed
 * to match ColumnGroups. Expects the relations of activeEmployees() to be loaded.
 */
class EmployeeRow
{
    /** A running contract is flagged once it ends within this many months. */
    public const PKWT_WARNING_MONTHS = 2;

    /** Profile columns that are not data fields of the record. */
    private const SKIP = ['id', 'employee_id', 'created_at', 'updated_at'];

    /** What flatten() reads. */
    public const RELATIONS = [
        'position', 'jobLevel', 'subLocation', 'workLocation', 'directorate', 'division.directorate', 'department.division.directorate', 'section.department.division.directorate',
        'profile.contracts', 'profile.children',
    ];

    /**
     * Everyone shown in Data Lengkap and the export: all but exited employees,
     * who have their own view.
     *
     * @return Collection<int, Karyawan>
     */
    public static function activeEmployees(): Collection
    {
        return Employee::where('status', '!=', Employee::STATUS_TERMINATED)
            ->with(self::RELATIONS)
            ->orderBy('employee_number')
            ->get();
    }

    /** Most children any of these employees has — decides how many child columns to show. */
    public static function maxChildren(Collection $employees): int
    {
        return $employees->max(fn (Employee $k) => $k->profile?->children->count() ?? 0) ?? 0;
    }

    /** @return array<string, string|int|float|bool|null> */
    public static function flatten(Employee $k, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $p = $k->profile;
        // The employee's own placement; the units above the one given are filled in.
        $department = $k->placementDepartment();
        $division = $k->division ?? $department?->division;

        $row = [
            'id' => $k->id,
            'employee_number' => $k->employee_number,
            'name' => $k->name,
            'email' => $k->email,
            'status' => $k->status,
            // Links the row to its card in the organisation chart (empty: waiting to be placed).
            'position_id' => $k->position_id,
            'work_location_id' => $k->work_location_id,
            'work_location_name' => $k->workLocation?->name,
            'sub_location_name' => $k->subLocation?->name,
            'job_level' => $k->jobLevel?->name,
            // Empty while waiting to be placed in Struktur Organisasi.
            'position_name' => $k->position?->name,
            'directorate_name' => ($k->directorate ?? $division?->directorate)?->name,
            'division_name' => $division?->name,
            'department_name' => $department?->name,
            'section_name' => $k->section?->name,
            'discipline' => $k->discipline,
            // Jenis karyawan, tempat penerimaan, status asal.
            ...EmployeeNumber::derived($k),
        ];

        if (! $p) {
            // No profile filled in yet — the base columns above are all there is.
            return $row + [
                'age' => null,
                'years_of_service' => null,
                'contract_end_status' => '-',
                'last_contract_ending_soon' => false,
                'medical_benefit_limit' => BenefitEntitlement::shared()->medicalBenefitLimit($k),
            ];
        }

        foreach (array_diff_key($p->attributesToArray(), array_flip(self::SKIP)) as $key => $value) {
            $row[$key] = $value;
        }

        $row['age'] = $p->age;
        $row['years_of_service'] = $p->years_of_service;
        $row['contract_end_status'] = $p->contract_end_status;
        $row['medical_benefit_limit'] = BenefitEntitlement::shared()->medicalBenefitLimit($k);

        // On screen a single contract is shown: the one that ends last. That is the
        // running one normally, an already-signed extension when there is one, and a
        // lapsed contract when nothing follows it — so HR can follow up.
        $latest = $p->latestContract();
        $row['last_contract_sequence'] = $latest ? EmploymentContract::label($latest->sequence_no) : null;
        $row['last_contract_start_date'] = $latest?->start_date->toDateString();
        $row['last_contract_end_date'] = $latest?->end_date->toDateString();
        $row['last_contract_days_left'] = $latest ? Dates::daysUntil($latest->end_date, $today) : null;
        $row['last_contract_ending_soon'] = $latest
            && Dates::isWithinMonths($latest->end_date, self::PKWT_WARNING_MONTHS, $today);

        // Per-period columns, used by the Excel export.
        foreach ($p->contracts as $period) {
            $row["contract_{$period->sequence_no}_start_date"] = $period->start_date->toDateString();
            $row["contract_{$period->sequence_no}_end_date"] = $period->end_date->toDateString();
        }

        foreach ($p->children as $child) {
            $n = $child->sort_order;
            $row["child_{$n}_name"] = $child->name;
            $row["child_{$n}_birth_date"] = $child->birth_date?->toDateString();
            $row["child_{$n}_education_level"] = $child->education_level;
            $row["child_{$n}_employment_status"] = $child->employment_status;
            $row["child_{$n}_marital_status"] = $child->marital_status;
        }

        return $row;
    }
}
