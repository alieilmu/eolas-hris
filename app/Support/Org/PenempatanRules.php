<?php

namespace App\Support\Org;

/**
 * Shape of a placement as sent by the forms. What is actually required
 * depends on the SubGroup, which JabatanComposer checks.
 */
class PenempatanRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'work_location_id' => ['required', 'integer', 'exists:work_locations,id'],
            'sub_location_id' => ['nullable', 'integer'],
            'sub_group_id' => ['required', 'integer', 'exists:sub_groups,id'],
            'bod_id' => ['nullable', 'integer'],
            'divisi_id' => ['nullable', 'integer'],
            'departemen_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'bidang' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['work_location_id' => 'Work Location', 'sub_location_id' => 'Sub Lokasi', 'sub_group_id' => 'SubGroup', 'bidang' => 'Nama Jabatan'];
    }
}
