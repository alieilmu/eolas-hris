<?php

namespace Database\Seeders;

use App\Models\Directorate;
use App\Models\Position;
use App\Models\LookupValue;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use App\Support\Organization\PositionComposer;
use Illuminate\Database\Seeder;
use RuntimeException;

class OrgStructureSeeder extends Seeder
{
    /**
     * The company's employee subgroups and how they name positions:
     * nama => [layer, sumber, gelar, gelar at Head Office]. Superiors are arranged in the chart.
     */
    public const SUB_GROUPS = [
        'BOD' => [6, 'directorate', null, null],
        'General Manager' => [5, 'division', 'GENERAL MANAGER', null],
        'Manager' => [4, 'department', 'MANAGER', null],
        'Project Manager' => [4, 'work_location', 'PROJECT MANAGER', null],
        'Deputy Project Manager' => [4, 'work_location', 'DEPUTY PROJECT MANAGER', null],
        'Section Head HO' => [3, 'section', 'SECTION HEAD', null],
        'Section Head Site' => [3, 'section', 'SECTION HEAD', null],
        'Departement Head' => [3, 'department', 'DEPT HEAD', null],
        'Officer HO/ Group Leader' => [2, 'discipline', 'GROUP LEADER', 'OFFICER'],
        'Junior Staff' => [2, 'discipline', 'JUNIOR STAFF', null],
        'FGDP' => [2, 'discipline', 'FGDP', null],
        'Operator' => [1, 'discipline', null, null],
        'Mekanik' => [1, 'discipline', null, null],
        'Nonom' => [1, 'discipline', null, null],
        'Welder' => [1, 'discipline', null, null],
    ];

    /** Divisi => departemen => its sections. */
    private const UNITS = [
        'OPERASIONAL' => ['PRODUKSI' => ['OPERASI TAMBANG'], 'MAINTENANCE' => ['WORKSHOP']],
        'UMUM & KEUANGAN' => ['HRGA' => ['PERSONALIA'], 'FINANCE' => ['AKUNTANSI']],
    ];

    /** name => [kode, type] */
    private const WORK_LOCATIONS = [
        'Head Office' => ['HO', WorkLocation::TYPE_HEAD_OFFICE],
        'Site Murung Raya' => ['MRY', WorkLocation::TYPE_SITE],
        'Site Rantau' => ['RTU', WorkLocation::TYPE_SITE],
    ];

    /** Sample of the "Nama Jabatan" master list (the bidang half of composed names). */
    private const BIDANG = ['ACCOUNTING', 'EMPLOYEE ADMINISTRATION', 'HSE FIELD', 'MECHANIC', 'PRODUCTION OPERATOR'];

    /**
     * Demo placements: [key, SubGroup, work location, unit level, unit name, bidang].
     * The key links rows here and in KaryawanSeeder; it is not stored. Names are composed,
     * e.g. MANAGER HRGA, SECTION HEAD WORKSHOP, OFFICER EMPLOYEE ADMINISTRATION.
     */
    public const JABATANS = [
        ['KDV-OPS', 'General Manager', 'Site Murung Raya', 'division', 'OPERASIONAL', null],
        ['KDV-UMK', 'General Manager', 'Head Office', 'division', 'UMUM & KEUANGAN', null],
        ['KDP-PRD', 'Departement Head', 'Site Murung Raya', 'department', 'PRODUKSI', null],
        ['KDP-MTC', 'Departement Head', 'Site Rantau', 'department', 'MAINTENANCE', null],
        ['KDP-HRG', 'Manager', 'Head Office', 'department', 'HRGA', null],
        ['KDP-FIN', 'Manager', 'Head Office', 'department', 'FINANCE', null],
        ['SPV-MTC', 'Section Head Site', 'Site Rantau', 'section', 'WORKSHOP', null],
        ['STF-HRG', 'Officer HO/ Group Leader', 'Head Office', 'section', 'PERSONALIA', 'EMPLOYEE ADMINISTRATION'],
        ['STF-FIN', 'Junior Staff', 'Head Office', 'section', 'AKUNTANSI', 'ACCOUNTING'],
        ['OPR-PRD', 'Operator', 'Site Murung Raya', 'section', 'OPERASI TAMBANG', 'PRODUCTION OPERATOR'],
        ['MKN-MTC', 'Mekanik', 'Site Rantau', 'section', 'WORKSHOP', 'MECHANIC'],
    ];

    /** @var array<string, int> demo key => jabatan id, for KaryawanSeeder */
    public static array $created = [];

    public function run(): void
    {
        $directorate = Directorate::create(['name' => 'Direktur Utama', 'sort_order' => 0]);

        // unit level => name => id
        $units = ['division' => [], 'department' => [], 'section' => []];
        foreach (self::UNITS as $divisiName => $departments) {
            $division = $directorate->divisions()->create(['name' => $divisiName, 'sort_order' => count($units['division'])]);
            $units['division'][$divisiName] = $division->id;
            foreach ($departments as $deptName => $sections) {
                $department = $division->departments()->create(['name' => $deptName, 'sort_order' => count($units['department'])]);
                $units['department'][$deptName] = $department->id;
                foreach ($sections as $order => $sectionName) {
                    $units['section'][$sectionName] = $department->sections()->create(['name' => $sectionName, 'sort_order' => $order])->id;
                }
            }
        }

        self::seedSubGroups();

        // Where people work; each position (and so each employee) belongs to one.
        foreach (self::WORK_LOCATIONS as $name => [$code, $type]) {
            WorkLocation::create(['name' => $name, 'code' => $code, 'type' => $type, 'sort_order' => WorkLocation::count()]);
        }

        foreach (self::BIDANG as $sort_order => $value) {
            LookupValue::firstOrCreate(['category' => PositionComposer::BIDANG, 'value' => $value], ['sort_order' => $sort_order]);
        }
        LookupValues::flush();

        self::$created = [];
        foreach (self::JABATANS as [$key, $jobLevel, $location, $unitLevel, $unitName, $discipline]) {
            [$position, $error] = PositionComposer::findOrCreate([
                'work_location_id' => WorkLocation::where('name', $location)->value('id'),
                'job_level_id' => JobLevel::where('name', $jobLevel)->value('id'),
                Position::UNIT_COLUMNS[$unitLevel] => $units[$unitLevel][$unitName],
                'discipline' => $discipline,
            ]);
            self::$created[$key] = $position?->id ?? throw new RuntimeException("Demo jabatan {$key}: {$error}");
        }
    }

    /** Creates or updates the SubGroups and their naming rules (also used to set up an existing database). */
    public static function seedSubGroups(): void
    {
        foreach (self::SUB_GROUPS as $nama => [$layer, $source, $title_prefix, $gelarHo]) {
            JobLevel::updateOrCreate(['name' => $nama], ['layer' => $layer, 'source' => $source, 'title_prefix' => $title_prefix, 'title_prefix_head_office' => $gelarHo]);
        }
    }
}
