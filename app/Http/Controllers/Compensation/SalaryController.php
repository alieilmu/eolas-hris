<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\SalaryScale;
use App\Support\Access\AccessScope;
use App\Support\Payroll\Salary;
use App\Support\Payroll\Ptkp;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comben → Data Gaji: each employee's golongan and step in the Struktur Skala Upah (with
 * its history), the Gaji Pokok it gives at their location, the Tunjangan Jabatan of their
 * SubGroup, and their status PTKP — the fixed part of payroll.
 */
class SalaryController extends Controller
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $location = $request->integer('location') ?: null;
        if ($location && ! $locations->contains('id', $location)) {
            $location = null;
        }
        $employees = Employee::where('status', Employee::STATUS_ACTIVE)
            ->when($location, fn ($q) => $q->where('work_location_id', $location))
            ->with(['workLocation:id,name,sort_order', 'jobLevel:id,name,layer', 'position:id,name', 'profile.children', 'salaries.salaryScale.grade'])
            ->orderBy('employee_number')->get();

        $rows = $employees->map(function (Employee $k) use ($today) {
            $position_title = Salary::current($k, $today);
            $base = $position_title ? Salary::amount($position_title->salary_scale_id, $k->work_location_id, $today->year) : null;
            $allowance = Salary::positionAllowance($k);
            $ptkp = Ptkp::status($k);

            return [
                'id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name,
                'location' => $k->workLocation?->name, 'location_order' => $k->workLocation?->sort_order ?? 0,
                'job_level' => $k->jobLevel?->name, 'position' => $k->position?->name,
                'employee_class' => $k->profile?->employee_class, 'gender' => $k->profile?->gender,
                'marital_status' => $k->profile?->marital_status, 'children' => $k->profile?->children->count() ?? 0,
                'grade' => $position_title?->salaryScale?->grade?->code ?? $k->profile?->grade,
                'step' => $position_title?->salaryScale?->step,
                'salary_scale_id' => $position_title?->salary_scale_id,
                'effective_date' => $position_title?->effective_date?->toDateString(),
                'base_salary' => $base, 'position_allowance' => $allowance,
                'fixed_pay' => $base === null ? null : $base + $allowance,
                'ptkp' => $ptkp, 'ter' => Ptkp::terCategory($ptkp),
                'issues' => ! $position_title ? 'Belum ada golongan/step' : ($base === null ? 'Nilai SSU kosong untuk lokasi/tahun ini' : null),
                'history' => $k->salaries->map(fn (EmployeeSalary $g) => [
                    'id' => $g->id, 'effective_date' => $g->effective_date->toDateString(), 'grade' => $g->salaryScale?->grade?->code,
                    'step' => $g->salaryScale?->step, 'reason' => EmployeeSalary::ALASAN[$g->reason] ?? $g->reason, 'notes' => $g->notes,
                    'amount' => Salary::amount($g->salary_scale_id, $k->work_location_id, $g->effective_date->year),
                ])->values(),
            ];
        })->sortBy([['location_order', 'asc'], ['name', 'asc']])->values();

        return Inertia::render('Compensation/Salaries', [
            'rows' => $rows,
            'locations' => $locations,
            'location' => $location,
            'summary' => [
                'employee' => $rows->count(),
                'total_base_salary' => $rows->sum('base_salary'),
                'total_allowance' => $rows->sum('position_allowance'),
                'total_fixed_pay' => $rows->sum('fixed_pay'),
                'issues' => $rows->whereNotNull('issues')->count(),
            ],
            'scale' => SalaryScale::with('grade:id,code,grade_group,sort_order')->get()
                ->sortBy([fn ($a, $b) => $a->grade->sort_order <=> $b->grade->sort_order, fn ($a, $b) => $a->sort_order <=> $b->sort_order])
                ->values()->map(fn (SalaryScale $s) => ['id' => $s->id, 'grade_group' => $s->grade->grade_group, 'grade' => $s->grade->code, 'step' => $s->step]),
            'reason' => EmployeeSalary::ALASAN,
            'can_edit' => $request->user()->canEdit('employee-compensation'),
        ]);
    }

    /** A new place in the scale from a date (a raise, a promotion, a correction). */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'salary_scale_id' => ['required', 'integer', 'exists:salary_scales,id'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(array_keys(EmployeeSalary::ALASAN))],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['salary_scale_id' => 'Golongan / step', 'effective_date' => 'Berlaku mulai']);
        $g = $employee->salaries()->create($data + ['created_by' => $request->user()->id]);
        // The profile's golongan follows (it decides the plafon of Comben).
        $employee->profile?->update(['grade' => $g->salaryScale->grade->code]);
        Inertia::flash('success', "Golongan {$g->salaryScale->grade->code}".($g->salaryScale->step ? " {$g->salaryScale->step}" : '')." untuk {$employee->name} disimpan.");

        return back();
    }

    public function destroy(EmployeeSalary $salary): RedirectResponse
    {
        abort_unless(Employee::whereKey($salary->employee_id)->exists(), 404); // scoped
        $salary->delete();
        Inertia::flash('success', 'Riwayat gaji dihapus.');

        return back();
    }
}
