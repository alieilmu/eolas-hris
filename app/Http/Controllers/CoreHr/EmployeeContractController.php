<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\EmploymentContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Contract periods (PKWT I–VI) of one employee. */
class EmployeeContractController extends Controller
{
    private const DATE_RULES = [
        'start_date' => ['required', 'date_format:Y-m-d'],
        'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
    ];

    private const MESSAGES = [
        'end_date.after_or_equal' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal kontrak.',
    ];

    /** Adds the next period — also how a contract is extended from the dashboard. */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate(self::DATE_RULES, self::MESSAGES);

        // The profile row must exist before contracts can hang off it.
        $profile = $employee->profile()->firstOrCreate();
        $count = $profile->contracts()->count();

        if ($count >= EmployeeProfile::PKWT_MAX_PERIODS) {
            throw ValidationException::withMessages([
                'start_date' => 'Maksimal '.EmployeeProfile::PKWT_MAX_PERIODS.' periode PKWT. Gunakan Pengangkatan Pegawai untuk status tetap.',
            ]);
        }

        $profile->contracts()->create([...$data, 'sequence_no' => $count + 1]);

        Inertia::flash('success', EmploymentContract::label($count + 1)." untuk {$employee->name} tersimpan.");

        return back();
    }

    public function update(Request $request, Employee $employee, EmploymentContract $contract): RedirectResponse
    {
        $this->ensureBelongs($employee, $contract);

        $contract->update($request->validate(self::DATE_RULES, self::MESSAGES));

        return back();
    }

    public function destroy(Employee $employee, EmploymentContract $contract): RedirectResponse
    {
        $this->ensureBelongs($employee, $contract);

        DB::transaction(function () use ($contract) {
            $profile = $contract->profile;
            $contract->delete();

            // Keep numbering contiguous so the next period gets the right label.
            foreach ($profile->contracts()->get()->values() as $i => $period) {
                if ($period->sequence_no !== $i + 1) {
                    $period->update(['sequence_no' => $i + 1]);
                }
            }
        });

        return back();
    }

    private function ensureBelongs(Employee $employee, EmploymentContract $contract): void
    {
        abort_unless($contract->profile->employee_id === $employee->id, 404);
    }
}
