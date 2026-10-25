<?php

namespace Database\Seeders;

use App\Models\EmployeeCategory;
use App\Models\WorkspaceSetup;
use App\Support\TimeClock\TimeClockDefaults;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use Illuminate\Database\Seeder;

/**
 * "Data sendiri": an empty workspace for a demo user's own data. Only what every company
 * shares is there (NRP employee types, the choice lists; shift codes and roster patterns
 * come with the migrations) — the organisation, employees and Comben are theirs to enter.
 */
class EolasBlankSeeder extends Seeder
{
    public function run(): void
    {
        // General employee types; the company renames them or sets its own codes (and format) in the sesi awal.
        foreach (['1' => 'Karyawan Tetap', '2' => 'Karyawan Kontrak', '3' => 'Harian Lepas', '4' => 'Magang'] as $code => $name) {
            EmployeeCategory::firstOrCreate(['code' => $code], ['name' => $name]);
        }
        LookupValues::seedDefaults();
        TimeClockDefaults::seed();
        EmployeeNumber::flush();
        LookupValues::flush();

        $this->call(EolasBrandingSeeder::class);
        // Its Super Admin starts with the sesi awal (company profile, NRP format, organisation, data migration).
        WorkspaceSetup::firstOrCreate([], ['status' => 'pending']);
    }
}
