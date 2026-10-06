<?php

namespace Database\Seeders;

use App\Models\Bod;
use App\Models\Jabatan;
use App\Models\MasterOption;
use App\Models\SubGroup;
use App\Models\WorkLocation;
use App\Support\Karyawan\MasterOptions;
use App\Support\Org\JabatanComposer;
use Illuminate\Database\Seeder;
use RuntimeException;

class OrgStructureSeeder extends Seeder
{
    /**
     * The company's employee subgroups and how they name positions:
     * nama => [layer, sumber, gelar, gelar at Head Office]. Superiors are arranged in the chart.
     */
    public const SUB_GROUPS = [
        'BOD' => [6, 'bod', null, null],
        'General Manager' => [5, 'divisi', 'GENERAL MANAGER', null],
        'Manager' => [4, 'departemen', 'MANAGER', null],
        'Project Manager' => [4, 'work_location', 'PROJECT MANAGER', null],
        'Deputy Project Manager' => [4, 'work_location', 'DEPUTY PROJECT MANAGER', null],
        'Section Head HO' => [3, 'section', 'SECTION HEAD', null],
        'Section Head Site' => [3, 'section', 'SECTION HEAD', null],
        'Departement Head' => [3, 'departemen', 'DEPT HEAD', null],
        'Officer HO/ Group Leader' => [2, 'bidang', 'GROUP LEADER', 'OFFICER'],
        'Junior Staff' => [2, 'bidang', 'JUNIOR STAFF', null],
        'FGDP' => [2, 'bidang', 'FGDP', null],
        'Operator' => [1, 'bidang', null, null],
        'Mekanik' => [1, 'bidang', null, null],
        'Nonom' => [1, 'bidang', null, null],
        'Welder' => [1, 'bidang', null, null],
    ];

    /** Divisi => departemen => its sections. */
    private const UNITS = [
        'OPERASIONAL' => ['PRODUKSI' => ['OPERASI TAMBANG'], 'MAINTENANCE' => ['WORKSHOP']],
        'UMUM & KEUANGAN' => ['HRGA' => ['PERSONALIA'], 'FINANCE' => ['AKUNTANSI']],
    ];

    /** name => [kode, type] */
    private const WORK_LOCATIONS = [
        'Head Office' => ['HO', 'Head Office'],
        'Site Murung Raya' => ['MRY', 'Site'],
        'Site Rantau' => ['RTU', 'Site'],
    ];

    /** Sample of the "Nama Jabatan" master list (the bidang half of composed names). */
    private const BIDANG = ['ACCOUNTING', 'EMPLOYEE ADMINISTRATION', 'HSE FIELD', 'MECHANIC', 'PRODUCTION OPERATOR'];

    /**
     * Demo placements: [key, SubGroup, work location, unit level, unit name, bidang].
     * The key links rows here and in KaryawanSeeder; it is not stored. Names are composed,
     * e.g. MANAGER HRGA, SECTION HEAD WORKSHOP, OFFICER EMPLOYEE ADMINISTRATION.
     */
    public const JABATANS = [
        ['KDV-OPS', 'General Manager', 'Site Murung Raya', 'divisi', 'OPERASIONAL', null],
        ['KDV-UMK', 'General Manager', 'Head Office', 'divisi', 'UMUM & KEUANGAN', null],
        ['KDP-PRD', 'Departement Head', 'Site Murung Raya', 'departemen', 'PRODUKSI', null],
        ['KDP-MTC', 'Departement Head', 'Site Rantau', 'departemen', 'MAINTENANCE', null],
        ['KDP-HRG', 'Manager', 'Head Office', 'departemen', 'HRGA', null],
        ['KDP-FIN', 'Manager', 'Head Office', 'departemen', 'FINANCE', null],
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
        $bod = Bod::create(['name' => 'Direktur Utama', 'order' => 0]);

        // unit level => name => id
        $units = ['divisi' => [], 'departemen' => [], 'section' => []];
        foreach (self::UNITS as $divisiName => $departemens) {
            $divisi = $bod->divisis()->create(['name' => $divisiName, 'order' => count($units['divisi'])]);
            $units['divisi'][$divisiName] = $divisi->id;
            foreach ($departemens as $deptName => $sections) {
                $departemen = $divisi->departemens()->create(['name' => $deptName, 'order' => count($units['departemen'])]);
                $units['departemen'][$deptName] = $departemen->id;
                foreach ($sections as $order => $sectionName) {
                    $units['section'][$sectionName] = $departemen->sections()->create(['name' => $sectionName, 'order' => $order])->id;
                }
            }
        }

        self::seedSubGroups();

        // Where people work; each position (and so each employee) belongs to one.
        foreach (self::WORK_LOCATIONS as $name => [$kode, $type]) {
            WorkLocation::create(['name' => $name, 'kode' => $kode, 'type' => $type, 'order' => WorkLocation::count()]);
        }

        foreach (self::BIDANG as $urutan => $nilai) {
            MasterOption::firstOrCreate(['kategori' => JabatanComposer::BIDANG, 'nilai' => $nilai], ['urutan' => $urutan]);
        }
        MasterOptions::flush();

        self::$created = [];
        foreach (self::JABATANS as [$key, $subGroup, $location, $unitLevel, $unitName, $bidang]) {
            [$jabatan, $error] = JabatanComposer::findOrCreate([
                'work_location_id' => WorkLocation::where('name', $location)->value('id'),
                'sub_group_id' => SubGroup::where('nama', $subGroup)->value('id'),
                Jabatan::UNIT_COLUMNS[$unitLevel] => $units[$unitLevel][$unitName],
                'bidang' => $bidang,
            ]);
            self::$created[$key] = $jabatan?->id ?? throw new RuntimeException("Demo jabatan {$key}: {$error}");
        }
    }

    /** Creates or updates the SubGroups and their naming rules (also used to set up an existing database). */
    public static function seedSubGroups(): void
    {
        foreach (self::SUB_GROUPS as $nama => [$layer, $sumber, $gelar, $gelarHo]) {
            SubGroup::updateOrCreate(['nama' => $nama], ['layer' => $layer, 'sumber' => $sumber, 'gelar' => $gelar, 'gelar_ho' => $gelarHo]);
        }
    }
}
