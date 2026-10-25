<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Support\Employees\EmployeeNumber;
use App\Http\Controllers\Controller;
use App\Models\PointOfHire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * NRP digits 2–3. The work location it maps to decides Lokal / Kiriman. A code
 * already carried by employees' NRPs cannot change or go.
 */
class PointOfHireController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $location = PointOfHire::create($this->validated($request));
        Inertia::flash('success', "Lokasi penerimaan {$location->code} — {$location->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, PointOfHire $lokasiPenerimaan): RedirectResponse
    {
        $data = $this->validated($request, $lokasiPenerimaan);

        if ($data['code'] !== $lokasiPenerimaan->code && ($used = $lokasiPenerimaan->employeeCount())) {
            throw ValidationException::withMessages(['code' => "Kode {$lokasiPenerimaan->code} dipakai di NRP {$used} karyawan, jadi tidak bisa diubah. Nama dan lokasi kerja tetap boleh diubah."]);
        }

        $lokasiPenerimaan->update($data);
        Inertia::flash('success', 'Lokasi penerimaan diperbarui. Status asal karyawan mengikuti pemetaan yang baru.');

        return back();
    }

    public function destroy(PointOfHire $lokasiPenerimaan): RedirectResponse
    {
        if ($used = $lokasiPenerimaan->employeeCount()) {
            throw ValidationException::withMessages(['code' => "Kode {$lokasiPenerimaan->code} dipakai di NRP {$used} karyawan, jadi tidak bisa dihapus."]);
        }

        $lokasiPenerimaan->delete();
        Inertia::flash('success', "Lokasi penerimaan {$lokasiPenerimaan->code} dihapus.");

        return back();
    }

    private function validated(Request $request, ?PointOfHire $current = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $length = EmployeeNumber::segmentLength('point_of_hire');

        return $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9]{'.($length ?? 1).','.($length ?? 4).'}$/', Rule::unique('points_of_hire', 'code')->ignore($current)],
            'name' => ['required', 'string', 'max:100'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
        ], [
            'code.regex' => $length ? "Kode lokasi penerimaan harus {$length} karakter (huruf/angka), sesuai Format Nomor Induk." : 'Kode lokasi penerimaan 1–4 karakter (huruf/angka).',
            'code.unique' => 'Kode :input sudah dipakai lokasi penerimaan lain.',
        ], ['code' => 'Kode', 'name' => 'Nama', 'work_location_id' => 'Lokasi kerja']);
    }
}
