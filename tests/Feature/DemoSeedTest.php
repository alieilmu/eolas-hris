<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use Database\Seeders\EolasDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo data a new demo workspace is provisioned with seeds without errors. */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_demo_data_seeds(): void
    {
        $this->seed(EolasDemoSeeder::class);

        $this->assertGreaterThan(0, Employee::count());
        $this->assertGreaterThan(0, AttendanceLog::count());
        $this->assertGreaterThan(0, OvertimeRecord::count());
    }
}
