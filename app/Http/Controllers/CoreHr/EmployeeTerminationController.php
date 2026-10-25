<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\LookupValues;
use App\Support\Employees\ProfileRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Recording an exit, and undoing one. The record is kept; it only leaves the active workforce. */
class EmployeeTerminationController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        if ($employee->status === Employee::STATUS_TERMINATED) {
            throw ValidationException::withMessages(['reason' => 'Karyawan ini sudah tercatat keluar.']);
        }

        $reasonOptions = LookupValues::values('termination_reason');
        $data = $request->validate([
            'reason' => ['required', Rule::in($reasonOptions)],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Alasan keluar wajib dipilih.',
            'reason.in' => 'Alasan keluar wajib dipilih ('.implode(', ', $reasonOptions).').',
        ]);

        $hired = $employee->profile?->hire_date;
        if ($hired && $data['date'] < $hired->toDateString()) {
            throw ValidationException::withMessages([
                'date' => "Tanggal keluar tidak boleh sebelum tanggal masuk ({$hired->format('d.m.Y')}).",
            ]);
        }

        $employee->update([
            'status' => Employee::STATUS_TERMINATED,
            'termination_reason' => $data['reason'],
            'termination_date' => $data['date'],
            'termination_notes' => $data['description'] ?? null,
        ]);

        Inertia::flash('success', "{$employee->name} dicatat keluar ({$data['reason']}). Datanya kini ada di menu Karyawan Keluar.");

        return back();
    }

    /** Back to Aktif in the same Jabatan, e.g. when the exit was recorded by mistake. */
    public function destroy(Employee $employee): RedirectResponse
    {
        if ($employee->status !== Employee::STATUS_TERMINATED) {
            throw ValidationException::withMessages(['employee' => 'Karyawan ini tidak berstatus keluar.']);
        }

        // The old seat may have been filled in the meantime: then they wait to be placed again.
        $full = $employee->position_id ? ProfileRules::capacityError($employee->position_id, $employee->id) : null;

        $employee->update([
            'status' => Employee::STATUS_ACTIVE,
            'termination_reason' => null,
            'termination_date' => null,
            'termination_notes' => null,
            ...($full ? ['position_id' => null] : []),
        ]);

        Inertia::flash('success', "{$employee->name} aktif kembali.".($full ? " {$full} Tempatkan lagi lewat Struktur Organisasi." : ''));

        return back();
    }
}
