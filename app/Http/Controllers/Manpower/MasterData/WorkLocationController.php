<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Jabatan;
use App\Models\WorkLocation;
use App\Support\Org\JabatanComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkLocationController extends Controller
{
    public const TYPES = [WorkLocation::TYPE_HEAD_OFFICE, 'Site'];

    public function store(Request $request): RedirectResponse
    {
        $location = WorkLocation::create([...$this->validated($request), 'order' => WorkLocation::max('order') + 1]);
        Inertia::flash('success', "Lokasi kerja {$location->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $workLocation->update($this->validated($request, $workLocation));
        // Position names carry unit and place names.
        $renamed = JabatanComposer::refreshNames();
        Inertia::flash('success', 'Lokasi kerja diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(WorkLocation $workLocation): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $workLocation->karyawans()->count()) ? "{$n} karyawan (termasuk yang sudah keluar)" : null,
            ($n = Jabatan::where('work_location_id', $workLocation->id)->count()) ? "{$n} jabatan" : null,
            ($n = $workLocation->lokasiPenerimaans()->count()) ? "{$n} kode lokasi penerimaan" : null,
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
            'kode' => ['nullable', 'string', 'max:20', Rule::unique('work_locations', 'kode')->ignore($current)],
            'type' => ['required', Rule::in(self::TYPES)],
        ], [
            'kode.unique' => 'Kode ":input" sudah dipakai lokasi lain.',
        ], ['name' => 'Nama lokasi', 'kode' => 'Kode', 'type' => 'Tipe']);
    }
}
