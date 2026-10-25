<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Support\Employees\LookupValues;
use App\Support\Employees\ProfileSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Children of one employee. No cap on how many — four columns in the old sheet were a layout limit. */
class EmployeeChildController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $profile = $employee->profile()->firstOrCreate();
        $profile->children()->create([...$data, 'sort_order' => $profile->children()->count() + 1]);

        return back();
    }

    public function update(Request $request, Employee $employee, EmployeeChild $children): RedirectResponse
    {
        $this->ensureBelongs($employee, $children);

        $children->update($request->validate($this->rules()));

        return back();
    }

    public function destroy(Employee $employee, EmployeeChild $children): RedirectResponse
    {
        $this->ensureBelongs($employee, $children);

        DB::transaction(function () use ($children) {
            $profile = $children->profile;
            $children->delete();

            // Keep "Anak ke-N" contiguous.
            foreach ($profile->children()->get()->values() as $i => $child) {
                if ($child->sort_order !== $i + 1) {
                    $child->update(['sort_order' => $i + 1]);
                }
            }
        });

        return back();
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'education_level' => ['nullable', Rule::in(LookupValues::values('child_education_level'))],
            'employment_status' => ['nullable', Rule::in(ProfileSections::STATUS_BEKERJA_ANAK)],
            'marital_status' => ['nullable', Rule::in(ProfileSections::STATUS_MENIKAH_ANAK)],
        ];
    }

    private function ensureBelongs(Employee $employee, EmployeeChild $children): void
    {
        abort_unless($children->profile->employee_id === $employee->id, 404);
    }
}
