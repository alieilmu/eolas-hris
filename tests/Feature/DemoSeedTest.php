<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Position;
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

        // A company of 500 with its chain of command: one top position per work location,
        // every other one under a superior at the same place, everybody in a position.
        $this->assertGreaterThanOrEqual(500, Employee::where('status', Employee::STATUS_ACTIVE)->count());
        $this->assertSame(0, Employee::where('status', Employee::STATUS_ACTIVE)->whereNull('position_id')->count());
        $positions = Position::all()->keyBy('id');
        foreach ($positions->groupBy('work_location_id') as $location => $group) {
            $this->assertCount(1, $group->whereNull('parent_position_id'), "one top position at location {$location}");
        }
        foreach ($positions->load('jobLevel')->filter(fn (Position $p) => $p->jobLevel->isSingleHolder()) as $p) {
            $this->assertLessThanOrEqual(1, Employee::where('position_id', $p->id)->where('status', Employee::STATUS_ACTIVE)->count(), "{$p->name} is held by one person");
        }
        foreach ($positions->whereNotNull('parent_position_id') as $p) {
            $this->assertSame($p->work_location_id, $positions[$p->parent_position_id]->work_location_id, "{$p->name} reports to someone at its place");
        }
    }
}
