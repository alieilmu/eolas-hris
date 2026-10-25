<?php

namespace Database\Seeders;

use App\Support\Compensation\CompensationDefaults;
use Illuminate\Database\Seeder;

/** Master Data Comben (golongan, skala upah, plafon), after the SubGroups exist. */
class CompensationSeeder extends Seeder
{
    public function run(): void
    {
        CompensationDefaults::seed();
    }
}
