<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\WorkLocation;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkLocationController extends Controller
{
    public const TYPES = [WorkLocation::TYPE_HEAD_OFFICE, WorkLocation::TYPE_SITE];

    public function store(Request $request): RedirectResponse
    {
        $location = WorkLocation::create([...$this->validated($request), 'sort_order' => WorkLocation::max('sort_order') + 1]);
        Inertia::flash('success', "Lokasi kerja {$location->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $workLocation->update($this->validated($request, $workLocation));
        // Position names carry unit and place names.
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'Lokasi kerja diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(WorkLocation $workLocation): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $workLocation->employees()->count()) ? "{$n} karyawan (termasuk yang sudah keluar)" : null,
            ($n = Position::where('work_location_id', $workLocation->id)->count()) ? "{$n} jabatan" : null,
            ($n = $workLocation->pointsOfHire()->count()) ? "{$n} kode lokasi penerimaan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "{$workLocation->name} masih dipakai ".implode(' dan ', $blockers).'. Pindahkan dulu sebelum menghapus.']);
        }

        $workLocation->delete();
        Inertia::flash('success', "Lokasi kerja {$workLocation->name} dihapus.");

        return back();
    }

    private function validated(Request $request, ?WorkLocation $current = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('work_locations', 'name')->ignore($current)],
            // Short name used in titles, e.g. PROJECT MANAGER RMTU.
            'code' => ['nullable', 'string', 'max:20', Rule::unique('work_locations', 'code')->ignore($current)],
            'type' => ['required', Rule::in(self::TYPES)],
        ], [
            'code.unique' => 'Kode ":input" sudah dipakai lokasi lain.',
        ], ['name' => 'Nama lokasi', 'code' => 'Kode', 'type' => 'Tipe']);
    }
}
