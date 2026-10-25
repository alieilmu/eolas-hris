<?php

namespace App\Support\Employees;

use App\Models\Directorate;
use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Organization\PositionComposer;
use Inertia\Inertia;
use Inertia\OptionalProp;

/** Choices for placing an employee (Tambah Karyawan, Ubah Penempatan), shared by every page that opens those dialogs. */
class EmployeeFormOptions
{
    /**
     * Only loaded when a dialog opens (it reloads these props by name).
     *
     * @return array<string, OptionalProp>
     */
    public static function props(): array
    {
        return [
            'placement_options' => Inertia::optional(fn () => self::placementOptions()),
            'employee_number_codes' => Inertia::optional(fn () => self::employeeNumberCodes()),
        ];
    }

    /** Everything the placement picker needs to compose a position name the way the server will. */
    public static function placementOptions(): array
    {
        return [
            'work_locations' => WorkLocation::orderBy('sort_order')->get(['id', 'name', 'code', 'type']),
            'sub_locations' => SubLocation::orderBy('work_location_id')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'work_location_id']),
            'job_levels' => JobLevel::orderByDesc('layer')->orderBy('name')
                ->get(['id', 'name', 'layer', 'source', 'title_prefix', 'title_prefix_head_office']),
            'directorates' => Directorate::orderBy('sort_order')->get(['id', 'name']),
            'divisions' => Division::orderBy('sort_order')->get(['id', 'name', 'directorate_id']),
            'departments' => Department::orderBy('division_id')->orderBy('sort_order')->get(['id', 'name', 'division_id']),
            'sections' => Section::orderBy('department_id')->orderBy('sort_order')->get(['id', 'name', 'department_id']),
            'discipline' => LookupValues::values(PositionComposer::BIDANG),
            // Existing positions with who holds them.
            'positions' => Position::with(['employees' => fn ($q) => $q->active()->select('id', 'name', 'position_id')])
                ->orderBy('sort_order')->get(['id', 'name', 'job_level_id', 'work_location_id'])
                ->map(fn (Position $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'job_level_id' => $j->job_level_id,
                    'work_location_id' => $j->work_location_id,
                    'holders' => $j->employees->pluck('name'),
                ]),
        ];
    }

    /** What the dialog needs to build and explain an NRP. */
    public static function employeeNumberCodes(): array
    {
        return [
            'type' => collect(EmployeeNumber::categoryMap())->map(fn ($name, $code) => ['code' => (string) $code, 'name' => $name])->values(),
            'location' => collect(EmployeeNumber::pointOfHireMap())->map(fn ($l, $code) => ['code' => (string) $code, ...$l])->values(),
            'max_sequence' => EmployeeNumber::maxSequencePerPrefix(),
            'format' => ['mode' => EmployeeNumber::format()['mode'], 'segments' => EmployeeNumber::format()['segments'], 'max_length' => EmployeeNumber::format()['max_length'],
                'describe' => EmployeeNumber::describe(), 'example' => EmployeeNumber::example()],
        ];
    }
}
