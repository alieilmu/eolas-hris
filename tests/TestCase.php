<?php

namespace Tests;

use App\Models\Position;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
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
        EmployeeNumber::flush();
        LookupValues::flush();
    }

    /** A seeded position by its demo key in OrgStructureSeeder::JABATANS, e.g. "OPR-PRD". */
    protected function seededPosition(string $key): Position
    {
        return Position::findOrFail(OrgStructureSeeder::$created[$key] ?? throw new \InvalidArgumentException("Unknown seeded jabatan {$key}"));
    }

    protected function seededPositionName(string $key): string
    {
        return $this->seededPosition($key)->name;
    }
}
