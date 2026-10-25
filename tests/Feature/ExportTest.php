<?php

namespace Tests\Feature;

use App\Http\Controllers\Settings\UserController;
use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00');
        CarbonImmutable::setTestNow('2026-10-07 09:00:00');
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function employee(): Employee
    {
        return Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
    }

    private function book(TestResponse $response): Spreadsheet
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        return $book;
    }

    /** Every cell value of a sheet as one string, to look for a value anywhere. */
    private function text(Spreadsheet $book, string $sheet): string
    {
        return implode('|', array_map(fn (array $row) => implode('|', array_map('strval', $row)), $book->getSheetByNameOrThrow($sheet)->toArray(null, false, false)));
    }

    public function test_deklarasi_export_has_a_rekap_and_a_rincian_sheet(): void
    {
        $k = $this->employee();
        $d = BenefitClaim::create([
            'claim_number' => 'DKL/2026/10/0001', 'employee_id' => $k->id, 'date' => '2026-10-02', 'receipt_count' => 2,
            'accounting_month' => '2026-10', 'benefit_year' => 2026, 'status' => 'approved', 'source' => 'hr',
        ]);
        $d->items()->createMany([
            ['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000],
            ['type' => 'treatment', 'beneficiary' => 'spouse', 'amount' => 50000],
        ]);

        $book = $this->book($this->actingAs($this->admin())->get('/compensation/claims/export?month=2026-10'));

        $this->assertSame(['Claim Summary', 'Claim Items'], $book->getSheetNames());
        $rekap = $this->text($book, 'Claim Summary');
        $this->assertStringContainsString($k->employee_number, $rekap);
        $this->assertStringContainsString('200000', $rekap);
        $this->assertStringContainsString('Istri / Suami', $this->text($book, 'Claim Items'));
    }

    public function test_monitoring_export_has_pengobatan_and_kacamata(): void
    {
        $book = $this->book($this->actingAs($this->admin())->get('/compensation/benefit-balances/export?year=2026'));

        $this->assertSame(['Medical Limit 2026', 'Optical Limit 2026'], $book->getSheetNames());
        $this->assertStringContainsString($this->employee()->employee_number, $this->text($book, 'Medical Limit 2026'));
    }

    public function test_cuti_export_adds_cuti_besar_and_rincian(): void
    {
        $k = $this->employee();
        $k->profile()->update(['hire_date' => '2015-03-01']);
        $k->leaveTransactions()->create(['type' => 'annual', 'year' => 2026, 'start_date' => '2026-04-06', 'end_date' => '2026-04-08', 'days' => 3, 'source' => 'manual']);

        $book = $this->book($this->actingAs($this->admin())->get('/time/leave-balances/export'));

        $this->assertSame(['ANNUAL LEAVE', 'LONG SERVICE LEAVE', 'LEAVE TAKEN'], $book->getSheetNames());
        // Ten years in: two Cuti Besar periods.
        $this->assertSame(2, substr_count($this->text($book, 'LONG SERVICE LEAVE'), $k->employee_number));
        $this->assertStringContainsString('Cuti 2025 · 3 hr', $this->text($book, 'LEAVE TAKEN'));
    }

    public function test_trip_export_takes_the_trips_touching_the_months(): void
    {
        $k = $this->employee();
        $base = ['employee_id' => $k->id, 'origin_city' => 'Balikpapan', 'status' => 'approved', 'source' => 'hr'];
        LeaveTravelRequest::create([...$base, 'type' => 'business_trip', 'start_date' => '2026-09-29', 'end_date' => '2026-10-02', 'destination_city' => 'Jakarta', 'needs_cash_advance' => true, 'cash_advance_amount' => 2500000]);
        LeaveTravelRequest::create([...$base, 'type' => 'leave', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'destination_city' => 'Surabaya']);

        $book = $this->book($this->actingAs($this->admin())->get('/time/leave-travel/export?from=2026-10&until=2026-10&type=all'));
        $text = $this->text($book, 'Cuti & Dinas');

        $this->assertStringContainsString('Jakarta', $text);
        $this->assertStringContainsString('2500000', $text);
        $this->assertStringNotContainsString('Surabaya', $text);
    }

    public function test_exports_follow_the_menu_access(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->admin())->post('/settings/employee-accounts', ['employee_ids' => [$employee->id]]);
        $self_service = User::where('employee_id', $employee->id)->firstOrFail();

        foreach (['/compensation/claims/export', '/compensation/benefit-balances/export', '/time/leave-balances/export', '/time/leave-travel/export'] as $url) {
            $this->actingAs($self_service)->get($url)->assertForbidden();
        }
    }

    public function test_mass_reset_puts_every_ticked_account_back_to_the_first_password(): void
    {
        $people = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->orderBy('id')->take(3)->get();
        $this->actingAs($this->admin())->post('/settings/employee-accounts', ['employee_ids' => $people->pluck('id')->all()])->assertSessionHasNoErrors();
        $accounts = User::whereIn('employee_id', $people->pluck('id'))->get()->keyBy('employee_id');
        User::whereIn('id', $accounts->pluck('id'))->update(['password' => Hash::make('done-diganti-123'), 'must_change_password' => false]);

        // Two of the three ticked; an admin id in the list is ignored.
        $adminHash = $this->admin()->password;
        $ticked = [$accounts[$people[0]->id]->id, $accounts[$people[1]->id]->id, $this->admin()->id];
        $this->actingAs($this->admin())->post('/settings/employee-accounts/bulk-reset', ['user_ids' => $ticked])->assertSessionHasNoErrors();

        foreach ([0, 1] as $i) {
            $u = $accounts[$people[$i]->id]->fresh();
            $this->assertTrue(Hash::check(UserController::initialPassword($people[$i]), $u->password));
            $this->assertTrue($u->must_change_password);
        }
        $untouched = $accounts[$people[2]->id]->fresh();
        $this->assertTrue(Hash::check('done-diganti-123', $untouched->password));
        $this->assertFalse($untouched->must_change_password);
        $this->assertSame($adminHash, $this->admin()->fresh()->password);
    }

    public function test_mass_reset_is_for_the_super_admin_only(): void
    {
        $hr = User::where('role', User::ROLE_HR)->firstOrFail();
        $this->actingAs($hr)->post('/settings/employee-accounts/bulk-reset', ['user_ids' => [1]])->assertForbidden();
    }
}
