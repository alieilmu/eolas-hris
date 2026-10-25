<?php

namespace Tests\Feature\CoreHr;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\Completeness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Runs against the demo seed, whose scenarios are relative to today (see KaryawanSeeder). */
class EmployeeViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Explicit: the schema is migrated once per run, so a $seed flag only works for the first test class.
        $this->seed();
    }

    private function hr(): User
    {
        return User::where('role', User::ROLE_HR)->firstOrFail();
    }

    public function test_viewers_get_the_access_denied_page_on_every_view(): void
    {
        $viewer = User::where('email', 'viewer@eolas.local')->firstOrFail();

        foreach (['/core-hr/employees', '/core-hr/employees/full-data', '/core-hr/employees/termination'] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertForbidden()
                ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
        }
    }

    public function test_dashboard_counts_active_employees_only(): void
    {
        $this->actingAs($this->hr())->get('/core-hr/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CoreHr/Employees/Dashboard')
                ->where('dashboard.total_active', 14) // 15 seeded, 1 resigned
                ->where('dashboard.origin', ['local' => 11, 'transferred' => 3, 'not_filled' => 0]) // Agus, Indah, Dimas
                ->where('dashboard.employment_status', ['contract' => 8, 'permanent' => 6])
                ->has('dashboard.completeness.groups', 19));
    }

    public function test_dashboard_follow_up_lists(): void
    {
        $response = $this->actingAs($this->hr())->get('/core-hr/employees');
        $names = fn (string $key) => array_column($response->inertiaProps("dashboard.$key"), 'name');

        // Soonest first; Rudi's contract (55 days) is outside the 1-month window.
        $this->assertSame(['Fajar Nugroho', 'Yoga Pratama'], $names('contracts_ending_soon'));
        // Dewi's old contract lapsed too, but she is permanent so it is not a follow-up.
        $this->assertSame(['Maya Sari'], $names('contracts_ended'));
        $this->assertSame(['Joko Santoso', 'Hendra Wijaya'], $names('retirements'));
        // Born this month, by day: Indah (8th) and Siti (20th) always, plus whoever's fixed demo birthday falls in this month.
        $bornThisMonth = Employee::active()->with('profile')->get()
            ->filter(fn (Employee $k) => $k->profile?->birth_date?->month === today()->month)
            ->sortBy(fn (Employee $k) => $k->profile->birth_date->day)
            ->pluck('name')->values()->all();
        $this->assertSame($bornThisMonth, $names('birthdays'));
        $this->assertContains('Indah Permatasari', $bornThisMonth);
        $this->assertContains('Siti Rahmawati', $bornThisMonth);

        $fajar = $response->inertiaProps('dashboard.contracts_ending_soon.0');
        $this->assertSame(19, $fajar['days_left']);
        $this->assertSame(today()->addDays(20)->toDateString(), $fajar['next_start']);
    }

    public function test_data_lengkap_lists_everyone_but_exited_employees(): void
    {
        $response = $this->actingAs($this->hr())->get('/core-hr/employees/full-data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CoreHr/Employees/FullData')
                ->has('rows', 14)
                ->has('groups', 19)
                ->where('groups.0.locked', true));

        $rows = collect($response->inertiaProps('rows'))->keyBy('name');
        $this->assertArrayNotHasKey('Wahyu Kurniawan', $rows);

        $rudi = $rows['Rudi Hartono'];
        $this->assertSame('PKWT III', $rudi['last_contract_sequence']);
        $this->assertTrue($rudi['last_contract_ending_soon']); // 55 days: inside the 2-month warning
        $this->assertFalse($rows['Rina Marlina']['last_contract_ending_soon']);

        // Dates stay plain Y-m-d, with no timezone shift.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $rudi['birth_date']);
        $this->assertSame('1988-03-30', $rudi['birth_date']);

        // Permanent staff still show their last (lapsed) contract.
        $this->assertSame('Berakhir', $rows['Dewi Lestari']['contract_end_status']);
        $this->assertNull($rows['Lina Agustina']['national_id_number']);
        // Read from the NRP: Rudi 1-03-23-101 = Karyawan Normal hired at Rantau, working at Site Rantau.
        $this->assertSame('Karyawan Normal', $rudi['employee_category']);
        $this->assertSame('Rantau', $rudi['hire_location']);
        $this->assertSame('Lokal', $rudi['origin_status']);
        // Indah was hired at Murung Raya and works at Head Office.
        $this->assertSame('Kiriman', $rows['Indah Permatasari']['origin_status']);
        $this->assertSame('Operator Magang', $rows['Lina Agustina']['employee_category']);
    }

    public function test_keluar_lists_exited_employees_with_service_up_to_the_exit_date(): void
    {
        $wahyu = Employee::where('name', 'Wahyu Kurniawan')->with('profile')->firstOrFail();
        $expected = $wahyu->profile->yearsOfServiceOn($wahyu->termination_date);

        $this->actingAs($this->hr())->get('/core-hr/employees/termination')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CoreHr/Employees/Terminated')
                ->has('rows', 1)
                ->where('rows.0.name', 'Wahyu Kurniawan')
                ->where('rows.0.termination_reason', 'Resign')
                ->where('rows.0.years_of_service', $expected)
                ->has('reason_options', 8));

        $this->assertNotSame($wahyu->profile->years_of_service, $expected, 'masa kerja must stop at the exit date');
    }

    public function test_completeness_skips_groups_that_do_not_apply(): void
    {
        // Dewi: permanent and single — no PKWT, spouse, in-laws or children to fill.
        $dewi = Employee::where('name', 'Dewi Lestari')->with('profile.contracts', 'profile.children')->firstOrFail();
        $scores = Completeness::score($dewi->profile);

        foreach (['contract', 'spouse', 'in_laws', 'children'] as $group) {
            $this->assertFalse($scores[$group]['applicable'], $group);
        }
        $this->assertTrue($scores['appointment']['applicable']);
        $this->assertSame(2, $scores['employment']['total'], 'no marriage date for someone single');
        $this->assertSame(100, Completeness::overallPercent($scores));

        // Lina: deliberately incomplete profile.
        $lina = Employee::where('name', 'Lina Agustina')->with('profile.contracts', 'profile.children')->firstOrFail();
        $this->assertLessThan(50, Completeness::overallPercent(Completeness::score($lina->profile)));
    }
}
