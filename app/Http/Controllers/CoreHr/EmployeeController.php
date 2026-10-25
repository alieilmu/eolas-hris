<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\ColumnGroups;
use App\Support\Employees\Dashboard;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\EmployeeRow;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use App\Support\Organization\Placement;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Man Power views and the employee record itself. Admin / HR only (see routes). */
class EmployeeController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('CoreHr/Employees/Dashboard', [
            'dashboard' => Dashboard::build(),
        ]);
    }

    public function fullData(): Response
    {
        $today = CarbonImmutable::today();
        $employees = EmployeeRow::activeEmployees();

        return Inertia::render('CoreHr/Employees/FullData', [
            'rows' => $employees->map(fn (Employee $k) => EmployeeRow::flatten($k, $today))->values(),
            'groups' => ColumnGroups::build(EmployeeRow::maxChildren($employees), ColumnGroups::PKWT_ACTIVE),
            ...EmployeeFormOptions::props(),
            'reason_options' => LookupValues::values('termination_reason'),
        ]);
    }

    public function termination(): Response
    {
        $employees = Employee::termination()
            ->with(['position', 'department', 'section.department', 'workLocation', 'profile'])
            ->orderByDesc('termination_date')
            ->orderBy('employee_number')
            ->get();

        return Inertia::render('CoreHr/Employees/Terminated', [
            'rows' => $employees->map(fn (Employee $k) => [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'position_name' => $k->position?->name ?? '-',
                'department_name' => $k->placementDepartment()?->name ?? '-',
                'work_location_name' => $k->workLocation?->name,
                'hire_date' => $k->profile?->hire_date?->toDateString(),
                'termination_date' => $k->termination_date?->toDateString(),
                // Length of service up to the exit date, not today.
                'years_of_service' => $k->profile?->yearsOfServiceOn($k->termination_date ?? CarbonImmutable::today()),
                'termination_reason' => $k->termination_reason,
                'termination_notes' => $k->termination_notes,
            ])->values(),
            'reason_options' => LookupValues::values('termination_reason'),
        ]);
    }

    /** New employee with the essentials; the rest is completed on the profile page. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:20', 'unique:employees,employee_number'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'employee_number.unique' => 'NRP ":input" sudah digunakan.',
        ]);

        if ($error = EmployeeNumber::error($data['employee_number'])) {
            throw ValidationException::withMessages(['employee_number' => $error]);
        }

        // The position is the plan's one matching the placement, or one picked in the chart;
        // without a match the employee waits to be placed in Struktur Organisasi.
        ['attributes' => $placement, 'note' => $note] = Placement::resolve($request);
        $employee = DB::transaction(fn () => Employee::create([...$data, ...$placement, 'status' => Employee::STATUS_ACTIVE]));

        Inertia::flash('success', "{$employee->name} (NRP {$employee->employee_number}) ditambahkan.".($note ? " {$note}" : ' Lengkapi profilnya lewat ikon profil.'));

        return back();
    }

    /** Permanent: the profile, PKWT history and children go with it. Only for records entered by mistake. */
    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $request->validate([
            'employee_number_confirmation' => ['required', 'string'],
        ]);

        if (trim($request->string('employee_number_confirmation')) !== $employee->employee_number) {
            throw ValidationException::withMessages(['employee_number_confirmation' => "Ketik NRP {$employee->employee_number} untuk mengonfirmasi penghapusan."]);
        }

        $employee->delete();

        Inertia::flash('success', "Data {$employee->name} (NRP {$employee->employee_number}) dihapus permanen.");

        return back();
    }
}
