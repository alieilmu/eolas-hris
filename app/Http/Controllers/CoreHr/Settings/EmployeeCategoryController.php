<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Support\Employees\EmployeeNumber;
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
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $length = EmployeeNumber::segmentLength('category');

        return $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9]{'.($length ?? 1).','.($length ?? 4).'}$/', Rule::unique('employee_categories', 'code')->ignore($current)],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'code.regex' => $length ? "Kode jenis karyawan harus {$length} karakter (huruf/angka), sesuai Format Nomor Induk." : 'Kode jenis karyawan 1–4 karakter (huruf/angka).',
            'code.unique' => 'Kode :input sudah dipakai jenis karyawan lain.',
        ], ['code' => 'Kode', 'name' => 'Nama']);
    }
}
