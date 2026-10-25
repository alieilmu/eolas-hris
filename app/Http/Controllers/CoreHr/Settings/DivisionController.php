<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DivisionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $division = Division::create([...$this->validated($request), 'sort_order' => Division::max('sort_order') + 1]);
        Inertia::flash('success', "Divisi {$division->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $division->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'Divisi diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Division $division): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $division->departments()->count()) ? "{$n} departemen" : null,
            ($n = $division->positions()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Divisi {$division->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan atau hapus dulu.']);
        }

        $division->delete();
        Inertia::flash('success', "Divisi {$division->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'directorate_id' => ['required', 'integer', 'exists:directorates,id'],
        ], [], ['name' => 'Nama divisi', 'directorate_id' => 'BOD']);
    }
}
