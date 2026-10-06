<?php

namespace Database\Seeders;

use App\Support\Comben\CombenDefaults;
use Illuminate\Database\Seeder;

/** Master Data Comben (golongan, skala upah, plafon), after the SubGroups exist. */
class CombenSeeder extends Seeder
{
    public function run(): void
    {
        CombenDefaults::seed();
    }
}
