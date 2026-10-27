<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\PayrollComponent;
use App\Models\PayrollInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Pengaturan Kompensasi → Payroll → Komponen Input Variabel. */
class PayrollComponentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        PayrollComponent::create($this->validated($request) + ['is_system' => false]);
        Inertia::flash('success', 'Komponen ditambahkan.');

        return back();
    }

    public function update(Request $request, PayrollComponent $component): RedirectResponse
    {
        $data = $this->validated($request, $component);
        // A component in use keeps what it is (earning/deduction, taxable) so earlier slips still read right.
        if (PayrollInput::where('payroll_component_id', $component->id)->exists()) {
            unset($data['kind'], $data['taxable']);
        }
        $component->update($data);
        Inertia::flash('success', 'Komponen diperbarui.');

        return back();
    }

    public function destroy(PayrollComponent $component): RedirectResponse
    {
        if ($component->is_system || PayrollInput::where('payroll_component_id', $component->id)->exists()) {
            $component->update(['is_active' => false]);
            Inertia::flash('success', 'Komponen sudah pernah dipakai: dinonaktifkan, tidak dihapus.');
        } else {
            $component->delete();
            Inertia::flash('success', 'Komponen dihapus.');
        }

        return back();
    }

    private function validated(Request $request, ?PayrollComponent $c = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique(PayrollComponent::class, 'code')->ignore($c?->id),
                Rule::notIn(['base_salary', 'position_allowance', 'absence_deduction', 'overtime', 'tax_allowance', 'pph21', 'loan'])],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(array_keys(PayrollComponent::KINDS))],
            'nature' => ['required', Rule::in(array_keys(PayrollComponent::NATURES))],
            'taxable' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Kode hanya huruf kecil, angka dan garis bawah, mis. bonus_tahunan.'], ['code' => 'Kode']);
    }
}
