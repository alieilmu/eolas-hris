<?php

namespace Tests;

use App\Models\Jabatan;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\Nrp;
use Database\Seeders\OrgStructureSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests assert on Inertia props, not on built frontend assets.
        $this->withoutVite();

        // Per-process lookup caches must not leak between tests (each has its own database state).
        Nrp::flush();
        MasterOptions::flush();
    }

    /** A seeded position by its demo key in OrgStructureSeeder::JABATANS, e.g. "OPR-PRD". */
    protected function seededJabatan(string $key): Jabatan
    {
        return Jabatan::findOrFail(OrgStructureSeeder::$created[$key] ?? throw new \InvalidArgumentException("Unknown seeded jabatan {$key}"));
    }

    protected function seededJabatanName(string $key): string
    {
        return $this->seededJabatan($key)->name;
    }
}
