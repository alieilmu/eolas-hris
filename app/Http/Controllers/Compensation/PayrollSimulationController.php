<?php

namespace App\Http\Controllers\Compensation;

use App\Support\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSetting;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simulasi Gaji: one employee's slip for one period, worked out live by the payroll engine
 * (nothing is saved), and the employee's payroll choices: tax method and BPJS participation.
 */
class PayrollSimulationController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = PayrollSetting::current();
        $today = CarbonImmutable::today();
        $current = PayrollPeriod::of($today->year, $today->month, $settings->cutoff_day);
        $period = PayrollPeriod::fromCode($request->query('period'), $settings->cutoff_day) ?? $current;
        $employees = Employee::where('status', '!=', Employee::STATUS_TERMINATED)->orderBy('name')->get(['id', 'employee_number', 'name']);
        $employee = $request->integer('employee') ? Employee::with('payrollProfile')->find($request->integer('employee')) : null;

        $periods = [];
        for ($d = CarbonImmutable::create($today->year - 1, 1, 1); $d->lte($today->addMonths(2)); $d = $d->addMonth()) {
            $p = PayrollPeriod::of($d->year, $d->month, $settings->cutoff_day);
            $periods[] = ['code' => $p->code(), 'label' => $p->label().' ('.$p->range().')'];
        }

        return Inertia::render('Compensation/PayrollSimulation', [
            'employees' => $employees->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->employee_number} · {$e->name}"]),
            'periods' => array_reverse($periods),
            'period' => $period->code(),
            'employee_id' => $employee?->id,
            'slip' => $employee ? PayrollCalculator::month($employee, $period) : null,
            'profile' => $employee ? [
                'tax_method' => $employee->payrollProfile?->tax_method,
                'bpjs_health' => $employee->payrollProfile?->bpjs_health ?? true,
                'bpjs_employment' => $employee->payrollProfile?->bpjs_employment ?? true,
                'health_extra_members' => $employee->payrollProfile?->health_extra_members ?? 0,
            ] : null,
            'tax_methods' => PayrollSetting::TAX_METHODS,
            'default_tax_method' => $settings->default_tax_method,
            'can_edit' => $request->user()->canEdit('employee-compensation'),
        ]);
    }

    public function updateProfile(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'tax_method' => ['nullable', Rule::in(array_keys(PayrollSetting::TAX_METHODS))],
            'bpjs_health' => ['required', 'boolean'],
            'bpjs_employment' => ['required', 'boolean'],
            'health_extra_members' => ['sometimes', 'integer', 'min:0', 'max:10'],
        ]);
        EmployeePayrollProfile::updateOrCreate(['employee_id' => $employee->id], $data);
        Audit::log('payroll.profile_changed', $employee, "{$employee->employee_number} {$employee->name}", $data);
        Inertia::flash('success', "Pengaturan payroll {$employee->name} disimpan.");

        return back();
    }
}
