<?php

namespace Database\Seeders;

use App\Models\EmployeeCategory;
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
        foreach (['1' => 'Karyawan Normal', '7' => 'Tenaga Ahli', '8' => 'Mekanik Magang', '9' => 'Operator Magang'] as $code => $name) {
            EmployeeCategory::firstOrCreate(['code' => $code], ['name' => $name]);
        }
        LookupValues::seedDefaults();
        TimeClockDefaults::seed();
        EmployeeNumber::flush();
        LookupValues::flush();

        $this->call(EolasBrandingSeeder::class);
    }
}
