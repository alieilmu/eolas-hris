<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * "Data demo sistem": the full demo (DemoSeeder) under the Eloas white label.
 *
 *     php artisan migrate:fresh --seed --seeder=EloasDemoSeeder
 */
class EloasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([DemoSeeder::class, EloasBrandingSeeder::class]);
    }
}
