<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use App\Support\Employees\DataUpdate;
use App\Support\Employees\LookupValues;
use App\Support\Employees\ProfileSections;
use App\Support\Attachments\Attachments;
use App\Support\Notifications\RequestNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Portal Karyawan → Data Saya: the employee's own data, and changes to it sent to HR. */
class ProfileController extends Controller
{
    private function me(Request $request): Employee
    {
        return Employee::with(['position', 'workLocation', 'profile.children'])->findOrFail($request->user()->employee_id);
    }

    public function show(Request $request): Response
    {
        $k = $this->me($request);
        $history = EmployeeChangeRequest::with(['attachments', 'verifier'])->where('employee_id', $k->id)->latest('id')->limit(10)->get();

        return Inertia::render('SelfService/Profile', [
            'employee' => ['employee_number' => $k->employee_number, 'name' => $k->name, 'position' => $k->position?->name, 'work_location' => $k->workLocation?->name],
            ...DataUpdate::current($k),
            'sections' => DataUpdate::sections(),
            'options' => [
                'child_education_level' => LookupValues::values('child_education_level'),
                'child_employment_status' => ProfileSections::STATUS_BEKERJA_ANAK,
                'child_marital_status' => ProfileSections::STATUS_MENIKAH_ANAK,
            ],
            'pending' => ($pending = $history->firstWhere('status', 'pending')) ? DataUpdate::row($pending) : null,
            'history' => $history->where('status', '!=', 'pending')->map(fn (EmployeeChangeRequest $p) => DataUpdate::row($p))->values(),
            'attachment_limits' => Attachments::limits(),
        ]);
    }

    /** One request at a time: it waits for HR, then the data changes. */
    public function store(Request $request): RedirectResponse
    {
        $k = $this->me($request);
        if (EmployeeChangeRequest::where('employee_id', $k->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['changes' => 'Masih ada pengajuan yang menunggu HR. Batalkan dulu bila ingin mengubahnya.']);
        }
        [$changes, $children] = DataUpdate::diff($request, $k);
        $request->validate(Attachments::rules(), Attachments::messages());

        $p = DB::transaction(function () use ($k, $changes, $children, $request) {
            $p = EmployeeChangeRequest::create([
                'employee_id' => $k->id,
                'changes' => $changes,
                'children' => $children,
                'notes' => $request->input('notes') ?: null,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);
            Attachments::sync($request, $p, 'data-change-requests');

            return $p;
        });
        RequestNotifier::updateData($p);
        Inertia::flash('success', 'Pengajuan update data terkirim ('.(count($changes) + count($children)).' perubahan). Data berubah setelah disetujui HR.');

        return back();
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        EmployeeChangeRequest::where('employee_id', $request->user()->employee_id)->where('status', 'pending')->findOrFail($id)->delete();
        Inertia::flash('success', 'Pengajuan update data dibatalkan.');

        return back();
    }
}
