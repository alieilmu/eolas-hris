<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\EmployeeCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** NRP digit 1. A code already carried by employees' NRPs cannot change or go. */
class EmployeeCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $type = EmployeeCategory::create($this->validated($request));
        Inertia::flash('success', "Jenis karyawan {$type->code} — {$type->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, EmployeeCategory $jenisKaryawan): RedirectResponse
    {
        $data = $this->validated($request, $jenisKaryawan);

        if ($data['code'] !== $jenisKaryawan->code && ($used = $jenisKaryawan->employeeCount())) {
            throw ValidationException::withMessages(['code' => "Kode {$jenisKaryawan->code} dipakai di NRP {$used} karyawan, jadi tidak bisa diubah. Nama tetap boleh diubah."]);
        }

        $jenisKaryawan->update($data);
        Inertia::flash('success', 'Jenis karyawan diperbarui.');

        return back();
    }

    public function destroy(EmployeeCategory $jenisKaryawan): RedirectResponse
    {
        if ($used = $jenisKaryawan->employeeCount()) {
            throw ValidationException::withMessages(['code' => "Kode {$jenisKaryawan->code} dipakai di NRP {$used} karyawan, jadi tidak bisa dihapus."]);
        }

        $jenisKaryawan->delete();
        Inertia::flash('success', "Jenis karyawan {$jenisKaryawan->code} dihapus.");

        return back();
    }

    private function validated(Request $request, ?EmployeeCategory $current = null): array
    {
        return $request->validate([
            'code' => ['required', 'digits:1', Rule::unique('employee_categories', 'code')->ignore($current)],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'code.digits' => 'Kode jenis karyawan harus 1 digit angka (digit pertama NRP).',
            'code.unique' => 'Kode :input sudah dipakai jenis karyawan lain.',
        ], ['code' => 'Kode', 'name' => 'Nama']);
    }
}
