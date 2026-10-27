<?php

namespace Tests\Feature\Compensation;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Grade;
use App\Models\JobLevelAllowance;
use App\Models\OvertimeRecord;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\SalaryScaleAmount;
use App\Models\SalaryScalePeriod;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\Pph21;
use App\Support\Payroll\Salary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Payroll phase 3: stored payroll runs, their approval, slips in the portal and as PDF. */
class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-25 09:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-25 09:00:00');
        $this->seed();
        Salary::flush();
        Pph21::flush();
        // Everyone on Gaji Pokok 8.000.000 from January (no Tunjangan Jabatan, nothing else in the month).
        $grade = Grade::create(['grade_group' => 'TEST', 'code' => 'T1', 'sort_order' => 99]);
        $scale = SalaryScale::create(['grade_id' => $grade->id, 'step' => '1', 'sort_order' => 0]);
        $column = SalaryScalePeriod::create(['year' => 2026, 'work_location_id' => null, 'sort_order' => 99]);
        SalaryScaleAmount::create(['salary_scale_id' => $scale->id, 'salary_scale_period_id' => $column->id, 'amount' => 8_000_000]);
        EmployeeSalary::query()->delete();
        foreach (Employee::pluck('id') as $id) {
            EmployeeSalary::create(['employee_id' => $id, 'salary_scale_id' => $scale->id, 'effective_date' => '2026-01-01', 'reason' => 'initial']);
        }
        JobLevelAllowance::query()->update(['amount' => 0]);
        AttendanceRecord::query()->delete();
        ShiftSchedule::query()->delete();
        OvertimeRecord::query()->delete();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function hr(array $menus, array $locations = []): User
    {
        return User::create([
            'name' => 'HR '.uniqid(), 'email' => uniqid('hr').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => $menus, 'work_location_ids' => $locations, 'is_active' => true,
        ]);
    }

    private function onPayroll(string $period = '2026-10'): int
    {
        $run = new PayrollRun(['period' => $period]);
        $p = $run->periodOf();
        $run->period_start = $p->start;
        $run->period_end = $p->end;

        return \App\Support\Payroll\PayrollRunner::employees($run)->count();
    }

    public function test_a_run_goes_from_draft_to_locked_and_reaches_the_portal(): void
    {
        $maker = $this->hr(['payroll-run' => 'edit', 'payroll-approval' => 'edit']);
        $this->actingAs($maker)->post('/compensation/payroll', ['period' => '2026-10', 'pay_date' => '2026-10-28'])->assertRedirect();
        $run = PayrollRun::sole();
        $this->assertSame('draft', $run->status);
        $this->assertSame($this->onPayroll(), $run->employee_count);

        // The queue is synchronous here: worked out at once.
        $this->post("/compensation/payroll/{$run->id}/calculate")->assertRedirect();
        $run->refresh();
        $this->assertSame('calculated', $run->status);
        $this->assertSame($run->employee_count, Payslip::where('payroll_run_id', $run->id)->count());
        $this->assertSame(0, $run->totals['all']['errors']);
        $slip = Payslip::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertSame(8_000_000, $slip->lines()->where('code', 'base_salary')->value('amount'));
        $this->assertSame($slip->total_earnings - $slip->total_deductions, $slip->take_home_pay);
        $this->assertSame((int) Payslip::where('payroll_run_id', $run->id)->sum('take_home_pay'), $run->totals['all']['take_home_pay']);

        $this->get("/compensation/payroll/{$run->id}")->assertInertia(fn (Assert $p) => $p->component('Compensation/PayrollRun')
            ->where('run.status', 'calculated')->has('slips', $run->employee_count)->where('can.run', true));
        $this->getJson("/compensation/payroll/{$run->id}/slips/{$slip->id}")->assertOk()->assertJsonPath('take_home_pay', $slip->take_home_pay);

        // The employee sees nothing yet.
        $employee = User::create(['name' => $slip->name, 'email' => uniqid('k').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $slip->employee_id, 'is_active' => true]);
        $this->actingAs($employee)->get('/self-service/payslips')->assertInertia(fn (Assert $p) => $p->has('slips', 0)->where('slip', null));

        // Submitted by the maker: they cannot approve it themselves, the Super Admin can.
        $this->actingAs($maker)->post("/compensation/payroll/{$run->id}/submit")->assertRedirect();
        $this->assertSame('submitted', $run->fresh()->status);
        $this->post("/compensation/payroll/{$run->id}/approve")->assertForbidden();
        $this->post("/compensation/payroll/{$run->id}/calculate")->assertStatus(422);
        $this->actingAs($this->admin())->post("/compensation/payroll/{$run->id}/approve")->assertRedirect();
        $this->assertSame('approved', $run->fresh()->status);

        $this->actingAs($employee)->get('/self-service/payslips')->assertInertia(fn (Assert $p) => $p->component('SelfService/Payslips')
            ->has('slips', 1)->where('slip.id', $slip->id)->where('slip.take_home_pay', $slip->take_home_pay));
        $pdf = $this->get("/self-service/payslips/{$slip->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('/Encrypt', $pdf->getContent()); // opened with the birth date
        // Someone else's slip: not found.
        $other = Payslip::where('payroll_run_id', $run->id)->where('id', '!=', $slip->id)->firstOrFail();
        $this->get("/self-service/payslips/{$other->id}/pdf")->assertNotFound();

        $this->actingAs($maker)->post("/compensation/payroll/{$run->id}/paid", ['pay_date' => '2026-10-28'])->assertRedirect();
        $this->post("/compensation/payroll/{$run->id}/lock")->assertRedirect();
        $this->assertSame('locked', $run->fresh()->status);
        $this->delete("/compensation/payroll/{$run->id}")->assertStatus(422);

        $this->get("/compensation/payroll/{$run->id}/export")->assertOk()->assertDownload("payroll-2026-10-all.xlsx");
        $this->get("/compensation/payroll/{$run->id}/slips/{$slip->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_run_sent_back_can_be_worked_out_again(): void
    {
        $this->actingAs($this->admin())->post('/compensation/payroll', ['period' => '2026-10']);
        $run = PayrollRun::sole();
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $this->post("/compensation/payroll/{$run->id}/submit");
        $this->post("/compensation/payroll/{$run->id}/reject", ['note' => 'Lembur site belum masuk'])->assertRedirect();
        $this->assertSame('calculated', $run->fresh()->status);
        $this->assertSame('Lembur site belum masuk', $run->fresh()->rejection_note);
        $this->post("/compensation/payroll/{$run->id}/calculate")->assertRedirect();
        $this->assertNull($run->fresh()->rejection_note);
        $this->delete("/compensation/payroll/{$run->id}")->assertRedirect('/compensation/payroll');
        $this->assertSame(0, Payslip::count());
    }

    public function test_runs_of_one_period_do_not_overlap_and_locations_are_respected(): void
    {
        $locations = WorkLocation::orderBy('sort_order')->pluck('id');
        $this->actingAs($this->admin())->post('/compensation/payroll', ['period' => '2026-10', 'work_location_id' => $locations[0]])->assertSessionHasNoErrors();
        $this->post('/compensation/payroll', ['period' => '2026-10'])->assertSessionHasErrors('period');
        $this->post('/compensation/payroll', ['period' => '2026-10', 'work_location_id' => $locations[0]])->assertSessionHasErrors('period');
        $this->post('/compensation/payroll', ['period' => '2026-10', 'work_location_id' => $locations[1]])->assertSessionHasNoErrors();

        // An account for one location: only runs of that location.
        $site = $this->hr(['payroll-run' => 'edit'], [$locations[1]]);
        $this->actingAs($site)->post('/compensation/payroll', ['period' => '2026-11'])->assertSessionHasErrors('work_location_id');
        $this->post('/compensation/payroll', ['period' => '2026-11', 'work_location_id' => $locations[0]])->assertSessionHasErrors('work_location_id');
        $this->get('/compensation/payroll')->assertInertia(fn (Assert $p) => $p->has('runs', 1)->where('runs.0.work_location_id', $locations[1]));
        $first = PayrollRun::where('work_location_id', $locations[0])->sole();
        $this->get("/compensation/payroll/{$first->id}")->assertNotFound();

        // Without the menus: nothing.
        $this->actingAs($this->hr(['employees' => 'edit']))->get('/compensation/payroll')->assertForbidden();
        $viewer = $this->hr(['payroll-run' => 'view']);
        $this->actingAs($viewer)->get('/compensation/payroll')->assertOk();
        $this->post('/compensation/payroll', ['period' => '2026-12'])->assertForbidden();
    }

    public function test_joining_and_leaving_within_the_period_are_paid_pro_rata(): void
    {
        $period = PayrollPeriod::of(2026, 10, PayrollSetting::current()->cutoff_day); // 1–31 October
        [$joiner, $leaver] = Employee::where('status', Employee::STATUS_ACTIVE)->take(2)->get()->all();
        $everyone = $this->onPayroll();
        $joiner->profile->update(['hire_date' => '2026-10-22']); // 10 of 31 days
        $leaver->update(['termination_date' => '2026-10-15']); // 15 of 31 days

        $j = PayrollCalculator::month($joiner->fresh(), $period);
        $this->assertSame(10, $j['prorate']['days']);
        $this->assertSame((int) round(8_000_000 * round(10 / 31, 4)), $j['earnings'][0]['amount']);
        $this->assertSame('ter', $j['mode']);

        $l = PayrollCalculator::month($leaver->fresh(), $period);
        $this->assertSame(15, $l['prorate']['days']);
        $this->assertSame('annual', $l['mode']); // the year's tax in the last month
        $this->assertStringContainsString('Bulan terakhir', implode(' ', $l['warnings']));

        // Joined after the period: not on its payroll; left before it: not either.
        $joiner->profile->update(['hire_date' => '2026-11-02']);
        $leaver->update(['termination_date' => '2026-09-20', 'status' => Employee::STATUS_TERMINATED]);
        $this->assertSame($everyone - 2, $this->onPayroll());
    }

    public function test_december_builds_on_the_stored_slips(): void
    {
        $this->actingAs($this->admin())->post('/compensation/payroll', ['period' => '2026-11']);
        $run = PayrollRun::sole();
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $slip = Payslip::where('payroll_run_id', $run->id)->whereNull('error')->firstOrFail();
        $employee = $slip->employee;
        $december = PayrollPeriod::of(2026, 12, PayrollSetting::current()->cutoff_day);
        $withheld = fn (array $r) => -collect($r['tax_steps'])->first(fn ($s) => str_starts_with($s['label'], 'Sudah dipotong'))['amount'];

        // Not approved yet: November is worked out again like the other months.
        $before = PayrollCalculator::month($employee, $december);
        $this->assertStringContainsString('11 bulan sebelumnya', implode(' ', $before['warnings']));

        // Approved, with November's tax as stored (here: changed by hand to tell it apart).
        $run->update(['status' => 'approved']);
        $slip->update(['tax_withheld' => $slip->tax_withheld + 1_000]);
        $after = PayrollCalculator::month($employee->fresh(), $december);
        $this->assertStringContainsString('10 bulan sebelumnya', implode(' ', $after['warnings']));
        $this->assertSame($withheld($before) + 1_000, $withheld($after));
        $this->assertSame($before['tax'] - 1_000, $after['tax']);
    }

    public function test_bpjs_kesehatan_floor_and_extra_family_members(): void
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $k->workLocation->update(['minimum_wage' => 9_000_000]);
        $k->payrollProfile()->create(['health_extra_members' => 2]);
        $r = PayrollCalculator::month($k->fresh(), PayrollPeriod::of(2026, 10, 31));

        $health = collect($r['deductions'])->firstWhere('code', 'bpjs_health');
        $this->assertSame(90_000 * 3, $health['amount']); // 1% of the UMK 9 jt, for the employee and 2 more
        $this->assertSame(360_000, collect($r['company'])->firstWhere('code', 'bpjs_health_company')['amount']); // 4% of 9 jt
    }
}
