<?php

namespace Tests\Feature\Compensation;

use App\Models\AttendanceRecord;
use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Grade;
use App\Models\JobLevelAllowance;
use App\Models\LeaveTravelRequest;
use App\Models\Loan;
use App\Models\LongServiceLeave;
use App\Models\OvertimeRecord;
use App\Models\PayrollInput;
use App\Models\PayrollRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\SalaryScaleAmount;
use App\Models\SalaryScalePeriod;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Support\Payroll\Loans;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\Pph21;
use App\Support\Payroll\Ptkp;
use App\Support\Payroll\Salary;
use App\Support\Security\TwoFactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Payroll phase 5: THR per holiday, final pay, and amounts pulled from other modules. */
class PayrollPhaseFiveTest extends TestCase
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
        $grade = Grade::create(['grade_group' => 'TEST', 'code' => 'T1', 'sort_order' => 99]);
        $scale = SalaryScale::create(['grade_id' => $grade->id, 'step' => '1', 'sort_order' => 0]);
        $column = SalaryScalePeriod::create(['year' => 2026, 'work_location_id' => null, 'sort_order' => 99]);
        SalaryScaleAmount::create(['salary_scale_id' => $scale->id, 'salary_scale_period_id' => $column->id, 'amount' => 8_000_000]);
        EmployeeSalary::query()->delete();
        foreach (Employee::pluck('id') as $id) {
            EmployeeSalary::create(['employee_id' => $id, 'salary_scale_id' => $scale->id, 'effective_date' => '2020-01-01', 'reason' => 'initial']);
        }
        JobLevelAllowance::query()->update(['amount' => 0]);
        AttendanceRecord::query()->delete();
        ShiftSchedule::query()->delete();
        OvertimeRecord::query()->delete();
        $this->actingAs($admin = User::where('role', User::ROLE_ADMIN)->firstOrFail());
        $admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->withSession([TwoFactor::SESSION_PASSED => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function newRun(string $type, array $extra = []): PayrollRun
    {
        $this->post('/compensation/payroll', ['type' => $type, 'period' => '2026-10', ...$extra])->assertSessionHasNoErrors();

        return PayrollRun::latest('id')->firstOrFail();
    }

    private function approve(PayrollRun $run): void
    {
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $this->post("/compensation/payroll/{$run->id}/submit");
        $this->post("/compensation/payroll/{$run->id}/approve")->assertSessionHasNoErrors();
    }

    public function test_thr_follows_religion_and_service_and_is_taxed_with_the_month(): void
    {
        $active = Employee::where('status', Employee::STATUS_ACTIVE)->with('profile')->get();
        [$long, $short, $new, $other] = $active->take(4)->all();
        $long->profile->update(['religion' => 'Islam', 'hire_date' => '2020-01-01']);
        $short->profile->update(['religion' => 'Islam', 'hire_date' => '2026-04-10']); // 6 full months by 20 Oct
        $new->profile->update(['religion' => 'Islam', 'hire_date' => '2026-10-01']);   // under a month
        $other->profile->update(['religion' => 'Kristen', 'hire_date' => '2020-01-01']);

        $run = $this->newRun('thr', ['holiday' => 'idulfitri', 'holiday_date' => '2026-10-20']);
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $ids = Payslip::where('payroll_run_id', $run->id)->pluck('employee_id');
        $this->assertContains($long->id, $ids);
        $this->assertContains($short->id, $ids);
        $this->assertNotContains($new->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $slip = fn (Employee $k) => Payslip::where('payroll_run_id', $run->id)->where('employee_id', $k->id)->firstOrFail();
        $this->assertSame(8_000_000, $slip($long)->lines()->where('code', 'thr')->value('amount'));
        $this->assertSame(4_000_000, $slip($short)->lines()->where('code', 'thr')->value('amount'));

        // PPh21: TER on October's pay + THR, less TER on the pay alone; no BPJS.
        $k = $long->fresh();
        $month = PayrollCalculator::month($k, PayrollPeriod::of(2026, 10, 31))['gross'];
        $cat = Ptkp::terCategory(Ptkp::status($k));
        $r = PayrollRate::forYear(2026);
        $hasId = filled($k->profile->tax_id_number) || filled($k->profile->national_id_number);
        $this->assertSame(Pph21::monthly($cat, $month + 8_000_000, $r, $hasId) - Pph21::monthly($cat, $month, $r, $hasId), $slip($long)->tax);
        $this->assertSame(0, (int) $slip($long)->lines()->where('code', 'like', 'bpjs%')->count());

        // A second Idulfitri THR of the period is refused; Natal is its own run.
        $this->post('/compensation/payroll', ['type' => 'thr', 'period' => '2026-10', 'holiday' => 'idulfitri', 'holiday_date' => '2026-10-20'])->assertSessionHasErrors('period');
        $natal = $this->newRun('thr', ['holiday' => 'natal', 'holiday_date' => '2026-10-20']);
        $this->assertSame(Employee::where('status', '!=', Employee::STATUS_TERMINATED)->whereHas('profile', fn ($q) => $q->whereIn('religion', ['Kristen', 'Katolik'])->where('hire_date', '<=', '2026-09-20'))->count(), $natal->employee_count);
    }

    public function test_a_thr_paid_counts_in_the_year_end_tax(): void
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->with('profile')->firstOrFail();
        $k->profile->update(['religion' => 'Kristen', 'hire_date' => '2020-01-01']);
        $december = PayrollPeriod::of(2026, 12, 31);
        $before = PayrollCalculator::month($k->fresh(), $december);

        $this->post('/compensation/payroll', ['type' => 'thr', 'period' => '2026-12', 'holiday' => 'natal', 'holiday_date' => '2026-12-25']);
        $thr = PayrollRun::sole();
        $this->approve($thr);
        $slip = Payslip::where('payroll_run_id', $thr->id)->where('employee_id', $k->id)->firstOrFail();
        $after = PayrollCalculator::month($k->fresh(), $december);
        $gross = fn (array $r) => collect($r['tax_steps'])->first(fn ($s) => str_starts_with($s['label'], 'Bruto setahun'))['amount'];
        $this->assertSame($gross($before) + $slip->gross, $gross($after));
    }

    public function test_final_pay_takes_the_leavers_and_settles_their_loans(): void
    {
        [$leaver, $stayer] = Employee::where('status', Employee::STATUS_ACTIVE)->take(2)->get()->all();
        $loan = Loan::create(['employee_id' => $leaver->id, 'principal' => 3_000_000, 'tenor' => 3, 'installment' => 1_000_000, 'start_period' => '2026-09', 'status' => 'pending']);
        Loans::activate($loan, 1);
        $loan->installments()->where('sequence', 1)->update(['status' => 'deducted']);
        $leaver->update(['termination_date' => '2026-10-15']);

        $this->getJson('/compensation/payroll/leaving?period=2026-10')->assertJsonFragment(['id' => $leaver->id]);
        $this->post('/compensation/payroll', ['type' => 'final', 'period' => '2026-10', 'employee_ids' => [$stayer->id]])->assertSessionHasErrors('employee_ids');
        $final = $this->newRun('final', ['employee_ids' => [$leaver->id]]);
        $this->assertSame(1, $final->employee_count);
        $this->approve($final);
        $slip = Payslip::where('payroll_run_id', $final->id)->sole();
        $this->assertSame('annual', $slip->snapshot['mode']);
        $this->assertSame(2_000_000, (int) $slip->lines()->where('code', 'loan')->sum('amount')); // Oct + the rest
        $this->assertSame('paid_off', $loan->fresh()->status);

        // The monthly run of the period leaves the leaver out.
        $regular = $this->newRun('regular');
        $this->post("/compensation/payroll/{$regular->id}/calculate");
        $this->assertFalse(Payslip::where('payroll_run_id', $regular->id)->where('employee_id', $leaver->id)->exists());
        $this->assertTrue(Payslip::where('payroll_run_id', $regular->id)->where('employee_id', $stayer->id)->exists());
    }

    public function test_amounts_from_other_modules_are_pulled_once(): void
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        BenefitClaim::withoutGlobalScopes()->update(['status' => 'rejected']);
        LongServiceLeave::withoutGlobalScopes()->delete();
        LeaveTravelRequest::withoutGlobalScopes()->update(['field_leave_allowance' => 0]);

        $claim = BenefitClaim::create(['claim_number' => 'DK-TEST', 'employee_id' => $k->id, 'date' => '2026-10-05', 'accounting_month' => '2026-10', 'benefit_year' => 2026, 'status' => 'approved', 'provider_name' => 'RS Sehat']);
        $claim->items()->create(['type' => 'treatment', 'beneficiary' => 'employee', 'amount' => 750_000]);
        LongServiceLeave::create(['employee_id' => $k->id, 'sequence_no' => 1, 'decision' => 'cashed_out', 'status' => 'approved', 'verified_at' => '2026-10-10 10:00:00']);
        $trip = LeaveTravelRequest::create(['employee_id' => $k->id, 'type' => 'leave', 'leave_category' => 'field', 'leave_days' => 9,
            'start_date' => '2026-10-12', 'end_date' => '2026-10-20', 'status' => 'approved', 'field_leave_allowance' => 500_000, 'origin_city' => 'Balikpapan', 'destination_city' => 'Makassar']);

        $this->post('/compensation/payroll-inputs/pull', ['period' => '2026-10'])->assertSessionHasNoErrors();
        $inputs = PayrollInput::where('source', 'auto')->get()->keyBy('source_ref');
        $this->assertSame(750_000, (int) $inputs["claim:{$claim->id}"]->amount);
        $this->assertSame(16_000_000, (int) $inputs->first(fn ($i) => str_starts_with($i->source_ref, 'long_service:'))->amount); // 2 × 8 jt
        $this->assertSame(500_000, (int) $inputs["field_leave:{$trip->id}"]->amount);
        $this->assertTrue($inputs->every(fn ($i) => $i->status === 'pending'));

        $this->post('/compensation/payroll-inputs/pull', ['period' => '2026-10']);
        $this->assertSame(3, PayrollInput::where('source', 'auto')->count());
    }
}
