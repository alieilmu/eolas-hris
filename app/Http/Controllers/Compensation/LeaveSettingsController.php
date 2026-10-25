<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\FieldLeaveEntitlement;
use App\Models\PointOfHire;
use App\Models\FieldLeaveAllowance;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Pengaturan Kompensasi → Aturan Cuti, Cuti Lapangan per lokasi / sub lokasi, and its lumpsum. */
class LeaveSettingsController extends Controller
{
    public function updatePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'annual_entitlement_days' => ['required', 'integer', 'min:0', 'max:60'],
            'annual_validity_years' => ['required', 'integer', 'min:1', 'max:5'],
            'long_service_interval_years' => ['required', 'integer', 'min:1', 'max:20'],
            'long_service_days' => ['required', 'integer', 'min:0', 'max:120'],
            'long_service_taken_salary_multiplier' => ['required', 'numeric', 'min:0', 'max:12'],
            'long_service_cashout_salary_multiplier' => ['required', 'numeric', 'min:0', 'max:12'],
            'long_service_decision_months' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        LeavePolicy::current()->update($data);
        LeavePolicy::forget();
        Inertia::flash('success', 'Aturan cuti disimpan; saldo semua karyawan dihitung ulang dengan aturan ini.');

        return back();
    }

    public function storeFieldLeave(Request $request): RedirectResponse
    {
        FieldLeaveEntitlement::create($this->fieldData($request));
        Inertia::flash('success', 'Cuti lapangan ditambahkan.');

        return back();
    }

    public function updateFieldLeave(Request $request, FieldLeaveEntitlement $field): RedirectResponse
    {
        $field->update($this->fieldData($request, $field));
        Inertia::flash('success', 'Cuti lapangan diperbarui.');

        return back();
    }

    public function destroyFieldLeave(FieldLeaveEntitlement $field): RedirectResponse
    {
        $field->delete();
        Inertia::flash('success', 'Cuti lapangan dihapus.');

        return back();
    }

    /** The lumpsum of one site: per POH (lokasi penerimaan) and klasifikasi; empty = none. */
    public function updateFieldLeaveAllowance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')],
            'amount' => ['present', 'array'],
            'amount.*' => ['array'],
            'amount.*.*' => ['nullable', 'integer', 'min:0', 'max:999999999'],
        ], [], ['amount.*.*' => 'Nominal']);
        $point_of_hire = PointOfHire::pluck('id')->all();
        $employee_class = LookupValues::values('employee_class');
        DB::transaction(function () use ($data, $point_of_hire, $employee_class) {
            FieldLeaveAllowance::where('work_location_id', $data['work_location_id'])->delete();
            foreach ($data['amount'] as $pohId => $values) {
                foreach ($values as $k => $amount) {
                    if ($amount !== null && $amount !== '' && in_array((int) $pohId, $point_of_hire, true) && in_array($k, $employee_class, true)) {
                        FieldLeaveAllowance::create(['work_location_id' => $data['work_location_id'], 'point_of_hire_id' => (int) $pohId, 'employee_class' => $k, 'amount' => (int) $amount]);
                    }
                }
            }
        });
        Inertia::flash('success', 'Lumpsum cuti lapangan '.WorkLocation::whereKey($data['work_location_id'])->value('name').' disimpan; berlaku untuk pengajuan cuti lapangan berikutnya.');

        return back();
    }

    private function fieldData(Request $request, ?FieldLeaveEntitlement $current = null): array
    {
        $data = $request->validate([
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')],
            'sub_location_id' => ['nullable', 'integer', Rule::exists('sub_locations', 'id')],
            'leave_days' => ['required', 'integer', 'min:0', 'max:90'],
            'outbound_travel_days' => ['required', 'integer', 'min:0', 'max:10'],
            'return_travel_days' => ['required', 'integer', 'min:0', 'max:10'],
        ], [], ['work_location_id' => 'Lokasi', 'leave_days' => 'Hari cuti', 'outbound_travel_days' => 'Hari perjalanan berangkat', 'return_travel_days' => 'Hari perjalanan kembali']);
        if ($data['sub_location_id'] && SubLocation::whereKey($data['sub_location_id'])->value('work_location_id') !== (int) $data['work_location_id']) {
            throw ValidationException::withMessages(['sub_location_id' => 'Sub lokasi itu bukan bagian dari lokasi yang dipilih.']);
        }
        $taken = FieldLeaveEntitlement::where('work_location_id', $data['work_location_id'])
            ->where(fn ($q) => $data['sub_location_id'] ? $q->where('sub_location_id', $data['sub_location_id']) : $q->whereNull('sub_location_id'))
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['work_location_id' => 'Lokasi / sub lokasi ini sudah punya aturan cuti lapangan.']);
        }

        return $data;
    }
}
