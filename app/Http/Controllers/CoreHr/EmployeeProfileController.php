<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoreHr\UpdateEmployeeProfileRequest;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeProfile;
use App\Models\EmployeeChangeRequest;
use App\Models\EmploymentContract;
use App\Support\Compensation\BenefitEntitlement;
use App\Support\Employees\Dates;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use App\Support\Employees\ProfileRules;
use App\Support\Employees\ProfileSections;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The full 19-section profile of one employee. */
class EmployeeProfileController extends Controller
{
    /** The latest contract is flagged on the page once it ends within this many days. */
    private const EXPIRING_SOON_DAYS = 30;

    public function show(Employee $employee): Response
    {
        $employee->load('position', 'jobLevel', 'subLocation', 'department', 'section.department', 'workLocation', 'profile.contracts', 'profile.children');
        $profile = $employee->profile;
        $stored = $profile?->attributesToArray() ?? [];
        $keys = array_keys(ProfileSections::editableFields());
        $latest = $profile?->latestContract();
        $daysLeft = $latest ? Dates::daysUntil($latest->end_date, CarbonImmutable::today()) : null;

        return Inertia::render('CoreHr/Employees/Profile', [
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'email' => $employee->email,
                'status' => $employee->status,
                'position_id' => $employee->position_id,
                // Null while waiting to be placed in Struktur Organisasi.
                'position' => $employee->position?->name,
                // Reporting line from the organisation chart.
                'superior' => ($superior = $employee->position?->superior()) ? [
                    'position' => $superior->name,
                    'names' => Employee::active()->where('position_id', $superior->id)->orderBy('name')->pluck('name'),
                ] : null,
                'job_level' => $employee->jobLevel?->name,
                // e.g. "Section Workshop · Maintenance".
                'department' => $employee->position?->unitLabel()
                    ?? collect([$employee->section ? "Section {$employee->section->name}" : null, $employee->placementDepartment()?->name])->filter()->implode(' · '),
                'work_location' => $employee->workLocation?->name,
                'work_location_id' => $employee->work_location_id,
                'sub_location' => $employee->subLocation?->name,
                'termination_reason' => $employee->termination_reason,
                'termination_date' => $employee->termination_date?->toDateString(),
            ],
            // Every editable field, null when not filled in yet.
            'profile' => array_map(
                fn (string $key) => $stored[$key] ?? ($key === 'is_permanent' ? false : null),
                array_combine($keys, $keys),
            ),
            'computed' => [
                'age' => $profile?->age,
                'years_of_service' => $profile?->years_of_service,
                'contract_end_status' => $profile?->contract_end_status ?? '-',
                // Modul Comben.
                'ptkp_status' => BenefitEntitlement::statusPtkp($employee),
                'medical_benefit_limit' => self::benefitLimitText($employee),
                'work_location' => collect([$employee->workLocation?->name, $employee->subLocation?->name])->filter()->implode(' · '),
                ...EmployeeNumber::derived($employee),
            ],
            'employee_number_warning' => EmployeeNumber::yearWarning($employee->employee_number, $profile?->hire_date),
            'data_change' => ($pending = EmployeeChangeRequest::where('employee_id', $employee->id)->where('status', 'pending')->first())
                ? ['id' => $pending->id, 'quantity' => count($pending->changes ?? []) + count($pending->children ?? [])]
                : null,
            // Prefills "Ubah Penempatan"; its choices load when the dialog opens.
            'placement' => $employee->placement(),
            ...EmployeeFormOptions::props(),
            'contract' => ($profile?->contracts ?? collect())->map(fn (EmploymentContract $p) => [
                'id' => $p->id,
                'sequence_no' => $p->sequence_no,
                'label' => EmploymentContract::label($p->sequence_no),
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
            ])->values(),
            'children' => ($profile?->children ?? collect())->map(fn (EmployeeChild $a) => [
                'id' => $a->id,
                'sort_order' => $a->sort_order,
                'name' => $a->name,
                'birth_date' => $a->birth_date?->toDateString(),
                'education_level' => $a->education_level,
                'employment_status' => $a->employment_status,
                'marital_status' => $a->marital_status,
            ])->values(),
            'contract_expiring_soon' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= self::EXPIRING_SOON_DAYS,
            'sections' => ProfileSections::build(),
            'options' => [
                'max_contract' => EmployeeProfile::PKWT_MAX_PERIODS,
                'child_education_level' => LookupValues::values('child_education_level'),
                'child_employment_status' => ProfileSections::STATUS_BEKERJA_ANAK,
                'child_marital_status' => ProfileSections::STATUS_MENIKAH_ANAK,
            ],
        ]);
    }

    /** "Rp 2.250.000 / tahun (L1 · Keluarga)", or why there is none. */
    private static function benefitLimitText(Employee $employee): string
    {
        $e = BenefitEntitlement::shared();
        $benefit_limit = $e->medicalBenefitLimit($employee);
        if ($benefit_limit === null) {
            return 'Belum ada — SubGroup belum punya plafon di Pengaturan Kompensasi';
        }
        $text = 'Rp '.number_format($benefit_limit, 0, ',', '.').' / tahun ('.$e->medicalRule($employee)->layer.' · '.(BenefitEntitlement::family($employee) ? 'Keluarga' : 'Lajang').')';

        return ($warning = $e->warning($employee)) ? "{$text} — {$warning}" : $text;
    }

    public function update(UpdateEmployeeProfileRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();

        $profile = $employee->profile()->updateOrCreate([], $data);

        Inertia::flash('success', 'Profil tersimpan.');
        $warnings = array_values(array_filter([
            ProfileRules::accountHolderWarning($profile->bank_account_holder, $employee->name),
            EmployeeNumber::yearWarning($employee->employee_number, $profile->hire_date),
        ]));
        if ($warnings) {
            Inertia::flash('warnings', $warnings);
        }

        return back();
    }
}
