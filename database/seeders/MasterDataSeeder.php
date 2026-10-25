<?php

namespace Database\Seeders;

use App\Models\EmployeeCategory;
use App\Models\PointOfHire;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use Illuminate\Database\Seeder;

/** NRP codes and the default choice lists. Runs after OrgStructureSeeder (needs the work locations). */
class MasterDataSeeder extends Seeder
{
    /** NRP digit 1. */
    private const JENIS = [
        '1' => 'Karyawan Normal',
        '7' => 'Tenaga Ahli',
        '8' => 'Mekanik Magang',
        '9' => 'Operator Magang',
    ];

    /** NRP digits 2–3 => [nama, work location it stands for]. */
    private const LOKASI = [
        '01' => ['Head Office', 'Head Office'],
        '03' => ['Rantau', 'Site Rantau'],
        '11' => ['Head Office', 'Head Office'],
        '15' => ['Murung Raya', 'Site Murung Raya'],
    ];

    public function run(): void
    {
        foreach (self::JENIS as $code => $name) {
            EmployeeCategory::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $locationIds = WorkLocation::pluck('id', 'name');
        foreach (self::LOKASI as $code => [$name, $workLocation]) {
            PointOfHire::firstOrCreate(['code' => $code], ['name' => $name, 'work_location_id' => $locationIds[$workLocation] ?? null]);
        }

        LookupValues::seedDefaults();

        // Seeding runs without model events, so the lookup caches are cleared by hand.
        EmployeeNumber::flush();
        LookupValues::flush();
    }
}
