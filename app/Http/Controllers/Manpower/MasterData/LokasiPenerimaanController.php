<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\LokasiPenerimaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * NRP digits 2–3. The work location it maps to decides Lokal / Kiriman. A code
 * already carried by employees' NRPs cannot change or go.
 */
class LokasiPenerimaanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $lokasi = LokasiPenerimaan::create($this->validated($request));
        Inertia::flash('success', "Lokasi penerimaan {$lokasi->kode} — {$lokasi->nama} ditambahkan.");

        return back();
    }

    public function update(Request $request, LokasiPenerimaan $lokasiPenerimaan): RedirectResponse
    {
        $data = $this->validated($request, $lokasiPenerimaan);

        if ($data['kode'] !== $lokasiPenerimaan->kode && ($used = $lokasiPenerimaan->karyawanCount())) {
            throw ValidationException::withMessages(['kode' => "Kode {$lokasiPenerimaan->kode} dipakai di NRP {$used} karyawan, jadi tidak bisa diubah. Nama dan lokasi kerja tetap boleh diubah."]);
        }

        $lokasiPenerimaan->update($data);
        Inertia::flash('success', 'Lokasi penerimaan diperbarui. Status asal karyawan mengikuti pemetaan yang baru.');

        return back();
    }

    public function destroy(LokasiPenerimaan $lokasiPenerimaan): RedirectResponse
    {
        if ($used = $lokasiPenerimaan->karyawanCount()) {
            throw ValidationException::withMessages(['kode' => "Kode {$lokasiPenerimaan->kode} dipakai di NRP {$used} karyawan, jadi tidak bisa dihapus."]);
        }

        $lokasiPenerimaan->delete();
        Inertia::flash('success', "Lokasi penerimaan {$lokasiPenerimaan->kode} dihapus.");

        return back();
    }

    private function validated(Request $request, ?LokasiPenerimaan $current = null): array
    {
        return $request->validate([
            'kode' => ['required', 'digits:2', Rule::unique('lokasi_penerimaans', 'kode')->ignore($current)],
            'nama' => ['required', 'string', 'max:100'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
        ], [
            'kode.digits' => 'Kode lokasi penerimaan harus 2 digit angka (digit ke-2–3 NRP), mis. 03.',
            'kode.unique' => 'Kode :input sudah dipakai lokasi penerimaan lain.',
        ], ['kode' => 'Kode', 'nama' => 'Nama', 'work_location_id' => 'Lokasi kerja']);
    }
}
