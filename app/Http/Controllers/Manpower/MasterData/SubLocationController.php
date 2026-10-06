<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\SubLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Sub Lokasi Kerja: places under a work location for people assigned away from the site itself. */
class SubLocationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $subLocation = SubLocation::create([...$this->validated($request), 'order' => SubLocation::max('order') + 1]);
        Inertia::flash('success', "Sub Lokasi {$subLocation->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, SubLocation $subLocation): RedirectResponse
    {
        $data = $this->validated($request, $subLocation);
        if ((int) $data['work_location_id'] !== $subLocation->work_location_id && ($subLocation->jabatans()->exists() || $subLocation->karyawans()->exists())) {
            throw ValidationException::withMessages(['work_location_id' => "Sub Lokasi {$subLocation->name} sudah dipakai jabatan/karyawan, jadi Work Location-nya tidak bisa diganti."]);
        }
        $subLocation->update($data);
        Inertia::flash('success', 'Sub Lokasi diperbarui.');

        return back();
    }

    public function destroy(SubLocation $subLocation): RedirectResponse
    {
        $jabatan = $subLocation->jabatans()->count();
        $karyawan = $subLocation->karyawans()->count();
        if ($jabatan || $karyawan) {
            $what = implode(' dan ', array_filter([$jabatan ? "{$jabatan} jabatan" : null, $karyawan ? "{$karyawan} karyawan" : null]));
            throw ValidationException::withMessages(['name' => "Sub Lokasi {$subLocation->name} masih dipakai {$what}. Pindahkan dulu."]);
        }

        $subLocation->delete();
        Inertia::flash('success', "Sub Lokasi {$subLocation->name} dihapus.");

        return back();
    }

    private function validated(Request $request, ?SubLocation $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'work_location_id' => ['required', 'integer', 'exists:work_locations,id'],
        ], [], ['name' => 'Nama Sub Lokasi', 'work_location_id' => 'Work Location induk']);
        $data['name'] = trim($data['name']);

        $taken = SubLocation::where('work_location_id', $data['work_location_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Sub Lokasi \"{$data['name']}\" sudah ada di Work Location ini."]);
        }

        return $data;
    }
}
