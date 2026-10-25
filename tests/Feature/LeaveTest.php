<?php

namespace Tests\Feature;

use App\Models\LeavePolicy;
use App\Models\LongServiceLeave;
use App\Models\FieldLeaveEntitlement;
use App\Models\Employee;
use App\Models\ShiftCode;
use App\Models\SubLocation;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Leave\LeaveLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
        $this->seed();
        if (! ShiftCode::forType('leave')) {
            ShiftCode::create(['code' => 'CT', 'daily_work_schedule' => 'CUTI', 'description' => 'Cuti', 'type' => 'leave']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@eolas.local')->firstOrFail();
    }

    private function employee(string $hired): Employee
    {
        $k = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->firstOrFail();
        $k->profile->update(['hire_date' => $hired]);

        return $k->fresh('profile');
    }

    private function leave(Employee $k, string $category, string $from, string $to): TestResponse
    {
        return $this->post('/time/leave-travel', [
            'type' => 'leave', 'leave_category' => $category, 'employee_id' => $k->id, 'start_date' => $from, 'end_date' => $to,
            'origin_city' => 'Tanjung', 'destination_city' => 'Yogyakarta', 'attachment' => [UploadedFile::fake()->create('leave.pdf', 20, 'application/pdf')],
        ]);
    }

    public function test_the_annual_balance_follows_the_old_ledger(): void
    {
        // Values of the old LEDGER CUTI sheet on 08.07.2026.
        $at = CarbonImmutable::parse('2026-07-08');
        $t = LeaveLedger::annual(CarbonImmutable::parse('2019-05-06'), [2023 => 9, 2024 => 14, 2025 => 5, 2026 => 2], 0, $at);
        $this->assertSame([22, 0], [$t['balance'], $t['debt']]);

        // Taken beyond the balance: hutang, paid by the next quota.
        $t = LeaveLedger::annual(CarbonImmutable::parse('2023-01-10'), [2024 => 15], 0, $at);
        $this->assertSame([[2024, 12, 0, 3], [2025, 12, 9, 0], [2026, 12, 21, 0]], array_map(fn ($y) => [$y['year'], $y['entitlement'], $y['remaining'], $y['debt']], $t['year']));

        // A quota lives two years: what is left of 2024 is gone in 2026.
        $t = LeaveLedger::annual(CarbonImmutable::parse('2023-01-10'), [], 0, $at);
        $this->assertSame(24, $t['balance']);
        $this->assertSame(12, $t['expires_end_of_year']);
        // Per leave: which year's quota it came from, and the later quota that paid a hutang.
        $t = LeaveLedger::annual(CarbonImmutable::parse('2023-01-10'), [2024 => 15, 2025 => 10], 0, $at, [
            ['key' => 'a', 'year' => 2024, 'days' => 15, 'sequence' => '2024-05-01'],
            ['key' => 'b', 'year' => 2025, 'days' => 4, 'sequence' => '2025-02-01'],
            ['key' => 'c', 'year' => 2025, 'days' => 6, 'sequence' => '2025-08-01'],
        ]);
        $this->assertSame([['entitlement' => 2024, 'days' => 12, 'debt' => false], ['entitlement' => 2025, 'days' => 3, 'debt' => true]], $t['allocation']['a']);
        $this->assertSame([['entitlement' => 2025, 'days' => 4, 'debt' => false]], $t['allocation']['b']);
        $this->assertSame([['entitlement' => 2025, 'days' => 5, 'debt' => false], ['entitlement' => 2026, 'days' => 1, 'debt' => true]], $t['allocation']['c']);
        $this->assertSame([11, 0], [$t['balance'], $t['debt']]);

        // Not reached yet this year: the anniversary has not come.
        $this->assertSame(0, LeaveLedger::annual(CarbonImmutable::parse('2025-09-01'), [], 0, $at)['balance']);
    }

    public function test_field_leave_takes_only_what_goes_beyond_its_allowance(): void
    {
        $k = $this->employee('2018-02-01');
        FieldLeaveEntitlement::create(['work_location_id' => $k->work_location_id, 'leave_days' => 14, 'outbound_travel_days' => 2, 'return_travel_days' => 2]);
        $this->actingAs($this->hr());

        $this->leave($k, '', '2026-10-10', '2026-10-12')->assertSessionHasErrors(['leave_category' => 'Pilih jenis cuti.']);
        $before = LeaveLedger::summary($k)['annual']['balance'];

        // 20 days of cuti lapangan: 18 allowed, 2 from the annual quota.
        $this->leave($k, 'field', '2026-10-10', '2026-10-29')->assertSessionHasNoErrors();
        $leave_travel = LeaveTravelRequest::latest('id')->firstOrFail();
        $this->assertSame(['field', 20, 2, 0], [$leave_travel->leave_category, $leave_travel->leave_days, $leave_travel->annual_leave_deducted, $leave_travel->long_service_leave_deducted]);
        // A cuti tahunan takes all its days.
        $this->leave($k, 'annual', '2026-11-02', '2026-11-04')->assertSessionHasNoErrors();
        $this->assertSame($before - 5, LeaveLedger::summary($k)['annual']['balance']);

        // The page lists it as in the old sheet, and the export downloads.
        $this->get('/time/leave-balances')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Time/LeaveBalances')
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $k->id)['annual']['balance'] === $before - 5));
        $this->get("/time/leave-balances/employee/{$k->id}")->assertOk()->assertJsonPath('field.total', 18)->assertJsonCount(2, 'transaction');
        $this->get('/time/leave-balances/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Typed by HR (a leave before the app).
        $this->post("/time/leave-balances/employee/{$k->id}/transaction", ['type' => 'annual', 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days' => 2])->assertSessionHasNoErrors();
        $this->assertSame($before - 7, LeaveLedger::summary($k)['annual']['balance']);
    }

    public function test_cuti_besar_is_chosen_by_the_employee_and_used_by_a_cuti(): void
    {
        $k = $this->employee('2016-03-01');
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/employee-accounts', ['employee_ids' => [$k->id]]);
        $user = tap(User::where('employee_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);

        // 5 years (2021) passed its deadline: cashed in; 10 years (2026) waits for a choice.
        $period = collect(LeaveLedger::summary($k)['long_service']['period']);
        $this->assertSame(['auto_cashed_out', 'not_chosen'], $period->pluck('status')->all());

        $this->actingAs($user)->post('/self-service/long-service-leave', ['sequence_no' => 1, 'decision' => 'taken'])->assertSessionHasErrors('decision');
        $this->post('/self-service/long-service-leave', ['sequence_no' => 2, 'decision' => 'taken'])->assertSessionHasNoErrors();
        $choice = LongServiceLeave::firstOrFail();
        $this->assertSame('pending', $choice->status);

        $this->actingAs($this->hr())->get('/time/leave-balances?tab=long_service')->assertInertia(fn (Assert $page) => $page->where('long_service_pending', 1));
        $this->post("/time/leave-balances/long-service/{$choice->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();

        // 27 days of Cuti Besar: 25 from it, 2 from the annual quota.
        $this->leave($k, 'long_service', '2026-10-12', '2026-11-07')->assertSessionHasNoErrors();
        $leave_travel = LeaveTravelRequest::latest('id')->firstOrFail();
        $this->assertSame([25, 2, 2], [$leave_travel->long_service_leave_deducted, $leave_travel->annual_leave_deducted, $leave_travel->long_service_period]);
        $this->assertSame(0, collect(LeaveLedger::summary($k)['long_service']['period'])->firstWhere('sequence_no', 2)['remaining']);
        // None left to take.
        $this->leave($k, 'long_service', '2026-12-01', '2026-12-02')->assertSessionHasErrors('leave_category');
    }

    public function test_master_data_and_access(): void
    {
        $this->actingAs($this->hr());
        [$site, $other] = WorkLocation::orderBy('id')->limit(2)->pluck('id')->all();
        $sub = SubLocation::create(['work_location_id' => $site, 'name' => 'PIT UJI', 'sort_order' => 99]);

        $this->put('/compensation/settings/leave-policy', [
            'annual_entitlement_days' => 12, 'annual_validity_years' => 2, 'long_service_interval_years' => 5, 'long_service_days' => 25,
            'long_service_taken_salary_multiplier' => 1, 'long_service_cashout_salary_multiplier' => 2, 'long_service_decision_months' => 6,
        ])->assertSessionHasNoErrors();
        $this->assertSame(6, LeavePolicy::query()->value('long_service_decision_months'));

        // A sub location of another site; then the same one twice.
        $this->post('/compensation/settings/field-leave', ['work_location_id' => $other, 'sub_location_id' => $sub->id, 'leave_days' => 14, 'outbound_travel_days' => 2, 'return_travel_days' => 2])
            ->assertSessionHasErrors('sub_location_id');
        $this->post('/compensation/settings/field-leave', ['work_location_id' => $sub->work_location_id, 'sub_location_id' => $sub->id, 'leave_days' => 10, 'outbound_travel_days' => 1, 'return_travel_days' => 1])->assertSessionHasNoErrors();
        $this->post('/compensation/settings/field-leave', ['work_location_id' => $sub->work_location_id, 'sub_location_id' => $sub->id, 'leave_days' => 10, 'outbound_travel_days' => 1, 'return_travel_days' => 1])->assertSessionHasErrors('work_location_id');

        $viewer = User::where('email', 'viewer@eolas.local')->firstOrFail();
        $this->actingAs($viewer)->get('/time/leave-balances')->assertForbidden();
    }
}
