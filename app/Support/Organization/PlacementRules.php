<?php

namespace App\Support\Organization;

/**
 * Shape of a placement as sent by the forms. What is actually required
 * depends on the SubGroup, which JabatanComposer checks.
 */
class PlacementRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'work_location_id' => ['required', 'integer', 'exists:work_locations,id'],
            'sub_location_id' => ['nullable', 'integer'],
            'job_level_id' => ['required', 'integer', 'exists:job_levels,id'],
            'directorate_id' => ['nullable', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'discipline' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['work_location_id' => 'Work Location', 'sub_location_id' => 'Sub Lokasi', 'job_level_id' => 'SubGroup', 'discipline' => 'Nama Jabatan'];
    }
}
