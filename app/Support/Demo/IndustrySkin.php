<?php

namespace App\Support\Demo;

use App\Models\WorkLocation;
use App\Support\Employees\EmployeeNumber;
use App\Support\Employees\LookupValues;
use App\Support\Organization\PositionComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames freshly seeded demo data (a mining contractor) to another industry
 * (App\Support\Demo\Industry): names are matched exactly where a column holds a name,
 * and as phrases inside free text; position titles are then composed again.
 */
class IndustrySkin
{
    /** Columns holding free text that may mention a place, a role or a vacancy. */
    private const TEXT = [
        'points_of_hire' => ['name'],
        'employee_profiles' => ['birth_place', 'national_id_province', 'national_id_city', 'residential_city', 'residential_province', 'mailing_city', 'parents_city', 'in_laws_city'],
        'leave_travel_requests' => ['origin_city', 'destination_city', 'description'],
        'candidate_notes' => ['content'],
        'job_postings' => ['location'],
        'medical_benefit_limits' => ['position_label_head_office', 'position_label_site'],
        'optical_benefit_limits' => ['position_label_head_office', 'position_label_site'],
        'attendance_devices' => ['name'],
        'notifications' => ['data'],
    ];

    public static function apply(string $industry): void
    {
        $p = Industry::profile($industry);
        if (! $p) {
            return; // mining: the data as built
        }

        DB::transaction(function () use ($p) {
            // Work locations: name, code, kind of site, work schedule and JKK risk rate.
            foreach ($p['locations'] as $old => [$name, $code, $type, $scheme, $jkk]) {
                WorkLocation::where('name', $old)->update([
                    'name' => $name, 'code' => $code, 'type' => $type, 'work_schedule_scheme' => $scheme,
                    'daily_normal_hours' => $scheme === 'five_day' ? 8 : 7, 'jkk_rate' => $jkk,
                ]);
            }

            self::exact('divisions', 'name', $p['units'] ?? []);
            self::exact('departments', 'name', $p['units'] ?? []);
            self::exact('sections', 'name', $p['units'] ?? []);
            self::exact('job_levels', 'name', $p['job_levels'] ?? []);
            self::exact('job_levels', 'title_prefix', $p['title_prefixes'] ?? []);
            self::exact('job_levels', 'title_prefix_head_office', $p['title_prefixes'] ?? []);
            self::exact('lookup_values', 'value', $p['disciplines'] ?? [], fn ($q) => $q->where('category', PositionComposer::BIDANG));
            self::exact('positions', 'discipline', $p['disciplines'] ?? []);
            self::exact('employees', 'discipline', $p['disciplines'] ?? []);
            self::exact('grades', 'grade_group', $p['grade_groups'] ?? []);
            self::exact('employee_categories', 'name', $p['categories'] ?? []);
            self::exact('candidates', 'position', $p['answers'] ?? []);
            self::exact('job_posting_fields', 'label', $p['answers'] ?? []);

            // Vacancies, their choices and the answers given to them.
            foreach ($p['postings'] ?? [] as $old => [$title, $positionTitle, $description]) {
                DB::table('job_postings')->where('title', $old)->update(['title' => $title, 'position_title' => $positionTitle, 'description' => $description]);
            }
            self::json('job_posting_fields', 'options', $p['answers'] ?? []);
            // Answers: the choices, and places (e.g. "Domisili").
            self::json('applicants', 'data', ($p['answers'] ?? []) + ($p['places'] ?? []));

            // Places and phrases in free text: longest first, so "Site Murung Raya" goes before "Murung Raya".
            $text = array_map(fn ($l) => $l[0], $p['locations']) + ($p['text'] ?? []) + ($p['places'] ?? []);
            foreach (array_keys($p['job_levels'] ?? []) as $old) {
                $text[$old] ??= $p['job_levels'][$old];
            }
            uksort($text, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach (self::TEXT as $table => $columns) {
                foreach ($columns as $column) {
                    self::replace($table, $column, $text);
                }
            }
        });

        LookupValues::flush();
        EmployeeNumber::flush();
        // Titles like "SECTION HEAD WORKSHOP" or "PROJECT MANAGER MRY" follow the new names.
        PositionComposer::refreshNames();
    }

    /** Whole values of a column renamed by the map. */
    private static function exact(string $table, string $column, array $map, ?callable $scope = null): void
    {
        if (! $map || ! Schema::hasColumn($table, $column)) {
            return;
        }
        foreach ($map as $old => $new) {
            if ($old === $new) {
                continue;
            }
            $q = DB::table($table)->where($column, $old);
            if ($scope) {
                $scope($q);
            }
            $q->update([$column => $new]);
        }
    }

    /** String values inside a JSON column renamed by the map (whole values only). */
    private static function json(string $table, string $column, array $map): void
    {
        if (! $map || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $walk = function ($v) use (&$walk, $map) {
            return is_array($v) ? array_map($walk, $v) : (is_string($v) && isset($map[$v]) ? $map[$v] : $v);
        };
        foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
            $data = json_decode($row->{$column}, true);
            if (is_array($data) && ($new = $walk($data)) !== $data) {
                DB::table($table)->where('id', $row->id)->update([$column => json_encode($new, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /** Phrases replaced inside the text of a column. */
    private static function replace(string $table, string $column, array $map): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
            $value = (string) $row->{$column};
            // strtr replaces each phrase once, longest match first, so a new name is never replaced again.
            $new = strtr($value, $map);
            if ($new !== $value) {
                DB::table($table)->where('id', $row->id)->update([$column => $new]);
            }
        }
    }
}
