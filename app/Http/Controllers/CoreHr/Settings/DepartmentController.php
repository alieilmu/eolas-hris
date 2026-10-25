<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DepartmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $department = Department::create([...$this->validated($request), 'sort_order' => Department::max('sort_order') + 1]);
        Inertia::flash('success', "Departemen {$department->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'Departemen diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Department $department): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $department->sections()->count()) ? "{$n} section" : null,
            ($n = $department->positions()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Departemen {$department->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan atau hapus dulu.']);
        }

        $department->delete();
        Inertia::flash('success', "Departemen {$department->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'division_id' => ['required', 'integer', 'exists:divisions,id'],
        ], [], ['name' => 'Nama departemen', 'division_id' => 'Divisi']);
    }
}
