<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * "Data demo sistem": the full demo (DemoSeeder) under the Eolas white label.
 *
 *     php artisan migrate:fresh --seed --seeder=EolasDemoSeeder
 */
class EolasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([DemoSeeder::class, EolasSalaryDemoSeeder::class, EolasTimeClockDemoSeeder::class, EolasBrandingSeeder::class]);
        \App\Support\Demo\DemoCertifications::seed(\App\Support\Demo\Industry::DEFAULT);
    }
}
