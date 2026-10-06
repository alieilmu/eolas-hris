<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\JenisKaryawan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** NRP digit 1. A code already carried by employees' NRPs cannot change or go. */
class JenisKaryawanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $jenis = JenisKaryawan::create($this->validated($request));
        Inertia::flash('success', "Jenis karyawan {$jenis->kode} — {$jenis->nama} ditambahkan.");

        return back();
    }

    public function update(Request $request, JenisKaryawan $jenisKaryawan): RedirectResponse
    {
        $data = $this->validated($request, $jenisKaryawan);

        if ($data['kode'] !== $jenisKaryawan->kode && ($used = $jenisKaryawan->karyawanCount())) {
            throw ValidationException::withMessages(['kode' => "Kode {$jenisKaryawan->kode} dipakai di NRP {$used} karyawan, jadi tidak bisa diubah. Nama tetap boleh diubah."]);
        }

        $jenisKaryawan->update($data);
        Inertia::flash('success', 'Jenis karyawan diperbarui.');

        return back();
    }

    public function destroy(JenisKaryawan $jenisKaryawan): RedirectResponse
    {
        if ($used = $jenisKaryawan->karyawanCount()) {
            throw ValidationException::withMessages(['kode' => "Kode {$jenisKaryawan->kode} dipakai di NRP {$used} karyawan, jadi tidak bisa dihapus."]);
        }

        $jenisKaryawan->delete();
        Inertia::flash('success', "Jenis karyawan {$jenisKaryawan->kode} dihapus.");

        return back();
    }

    private function validated(Request $request, ?JenisKaryawan $current = null): array
    {
        return $request->validate([
            'kode' => ['required', 'digits:1', Rule::unique('jenis_karyawans', 'kode')->ignore($current)],
            'nama' => ['required', 'string', 'max:100'],
        ], [
            'kode.digits' => 'Kode jenis karyawan harus 1 digit angka (digit pertama NRP).',
            'kode.unique' => 'Kode :input sudah dipakai jenis karyawan lain.',
        ], ['kode' => 'Kode', 'nama' => 'Nama']);
    }
}
