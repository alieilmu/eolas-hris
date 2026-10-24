<?php

namespace Database\Seeders;

use App\Models\JenisKaryawan;
use App\Support\Kehadiran\KehadiranDefaults;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\Nrp;
use Illuminate\Database\Seeder;

/**
 * "Data sendiri": an empty workspace for a demo user's own data. Only what every company
 * shares is there (NRP employee types, the choice lists; shift codes and roster patterns
 * come with the migrations) — the organisation, employees and Comben are theirs to enter.
 */
class EolasKosongSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['1' => 'Karyawan Normal', '7' => 'Tenaga Ahli', '8' => 'Mekanik Magang', '9' => 'Operator Magang'] as $kode => $nama) {
            JenisKaryawan::firstOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
        MasterOptions::seedDefaults();
        KehadiranDefaults::seed();
        Nrp::flush();
        MasterOptions::flush();

        $this->call(EolasBrandingSeeder::class);
    }
}
