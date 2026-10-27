<?php

namespace Tests\Feature\Compensation;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Grade;
use App\Models\JobLevelAllowance;
use App\Models\Loan;
use App\Models\OvertimeRecord;
use App\Models\PayrollComponent;
use App\Models\PayrollInput;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\SalaryScaleAmount;
use App\Models\SalaryScalePeriod;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\Pph21;
use App\Support\Payroll\Salary;
use App\Support\Security\TwoFactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Payroll phase 4: Input Variabel (bonus, potongan) and employee loans, through to the payslip. */
class PayrollInputsLoansTest extends TestCase
{
    use RefreshDatabase;

    private Employee $k;

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
            EmployeeSalary::create(['employee_id' => $id, 'salary_scale_id' => $scale->id, 'effective_date' => '2026-01-01', 'reason' => 'initial']);
        }
        JobLevelAllowance::query()->update(['amount' => 0]);
        AttendanceRecord::query()->delete();
        ShiftSchedule::query()->delete();
        OvertimeRecord::query()->delete();
        $this->k = Employee::where('status', Employee::STATUS_ACTIVE)->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function signIn(User $user): static
    {
        if (! $user->hasTwoFactor() && TwoFactor::required($user)) {
            $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        }

        return $this->actingAs($user)->withSession([TwoFactor::SESSION_PASSED => true]);
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function hr(array $menus): User
    {
        return User::create(['name' => 'HR '.uniqid(), 'email' => uniqid('hr').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => $menus, 'is_active' => true]);
    }

    private function payComponent(string $code): PayrollComponent
    {
        return PayrollComponent::where('code', $code)->firstOrFail();
    }

    private function input(string $code, int $amount, string $status = 'approved'): PayrollInput
    {
        return PayrollInput::create(['employee_id' => $this->k->id, 'period' => '2026-10', 'payroll_component_id' => $this->payComponent($code)->id, 'amount' => $amount, 'status' => $status]);
    }

    private function slip(): array
    {
        return PayrollCalculator::month($this->k->fresh(), PayrollPeriod::of(2026, 10, 31));
    }

    public function test_approved_inputs_reach_the_slip_and_the_tax(): void
    {
        $plain = $this->slip();
        $this->input('bonus', 2_000_000, 'pending');
        $this->assertSame($plain['gross'], $this->slip()['gross']); // waiting: not paid

        $this->input('bonus', 2_000_000);
        $withBonus = $this->slip();
        $this->assertSame($plain['gross'] + 2_000_000, $withBonus['gross']);
        $this->assertGreaterThan($plain['tax'], $withBonus['tax']);
        $this->assertSame(2_000_000, collect($withBonus['earnings'])->firstWhere('code', 'bonus')['amount']);

        // Not taxable: paid, but outside the gross.
        $meal = $this->payComponent('meal_allowance');
        $meal->update(['taxable' => false]);
        $this->input('meal_allowance', 500_000);
        $r = $this->slip();
        $this->assertSame($withBonus['gross'], $r['gross']);
        $this->assertSame($withBonus['total_earnings'] + 500_000, $r['total_earnings']);

        // Before tax (lowers the gross) and after tax (only the take home pay).
        $this->input('correction_minus', 300_000);
        $this->input('cooperative', 100_000);
        $r2 = $this->slip();
        $this->assertSame($r['gross'] - 300_000, $r2['gross']);
        $this->assertSame(100_000, collect($r2['deductions'])->firstWhere('code', 'cooperative')['amount']);
        $this->assertSame($r2['total_earnings'] - $r2['total_deductions'], $r2['take_home_pay']);
    }

    public function test_inputs_are_entered_imported_and_approved_by_someone_else(): void
    {
        $maker = $this->hr(['payroll-inputs' => 'edit', 'payroll-approval' => 'edit']);
        $bonus = $this->payComponent('bonus');
        $this->signIn($maker)->post('/compensation/payroll-inputs', ['employee_id' => $this->k->id, 'period' => '2026-10', 'payroll_component_id' => $bonus->id, 'amount' => 750_000, 'notes' => 'Target Q3'])
            ->assertSessionHasNoErrors();
        $input = PayrollInput::sole();
        $this->assertSame('pending', $input->status);
        $this->post('/compensation/payroll-inputs/decide', ['ids' => [$input->id], 'decision' => 'approved'])->assertSessionHasErrors('ids');
        $this->signIn($this->admin())->post('/compensation/payroll-inputs/decide', ['ids' => [$input->id], 'decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $input->fresh()->status);

        // Excel: a wrong row keeps the whole file out.
        $other = Employee::where('status', Employee::STATUS_ACTIVE)->whereKeyNot($this->k->id)->firstOrFail();
        $file = function (array $rows) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->fromArray([['Employee Number', 'Component Code', 'Amount', 'Notes'], ...$rows]);
            $path = tempnam(sys_get_temp_dir(), 'pi').'.xlsx';
            (new Xlsx($book))->save($path);

            return new UploadedFile($path, 'inputs.xlsx', null, null, true);
        };
        $this->signIn($maker)->get('/compensation/payroll-inputs/template?period=2026-10')->assertOk()->assertDownload('payroll-inputs-2026-10.xlsx');
        $this->post('/compensation/payroll-inputs/import', ['period' => '2026-10', 'file' => $file([
            [$other->employee_number, 'incentive', 400000, 'Lembur proyek'],
            ['TIDAK-ADA', 'bonus', 100000, null],
            [$other->employee_number, 'unknown_code', 100000, null],
        ])])->assertSessionHasErrors(['file.0', 'file.1']);
        $this->assertSame(1, PayrollInput::count());
        $this->post('/compensation/payroll-inputs/import', ['period' => '2026-10', 'file' => $file([
            [$other->employee_number, 'incentive', 400000, 'Lembur proyek'],
            [$this->k->employee_number, 'cooperative', 50000, null],
        ])])->assertSessionHasNoErrors();
        $this->assertSame(2, PayrollInput::where('source', 'import')->where('status', 'pending')->count());

        $this->get('/compensation/payroll-inputs?period=2026-10')->assertInertia(fn (Assert $p) => $p->component('Compensation/PayrollInputs')->has('inputs', 3));
    }

    public function test_a_period_approved_takes_no_more_inputs_and_marks_what_it_paid(): void
    {
        $bonus = $this->input('bonus', 1_000_000);
        $admin = $this->admin();
        $this->signIn($admin)->post('/compensation/payroll', ['period' => '2026-10']);
        $run = PayrollRun::sole();
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $this->post("/compensation/payroll/{$run->id}/submit");
        $this->post("/compensation/payroll/{$run->id}/approve");
        $slip = Payslip::where('employee_id', $this->k->id)->sole();
        $this->assertSame($slip->id, $bonus->fresh()->payslip_id);
        $this->assertSame("input:{$bonus->id}", $slip->lines()->where('code', 'bonus')->value('ref'));

        $this->post('/compensation/payroll-inputs', ['employee_id' => $this->k->id, 'period' => '2026-10', 'payroll_component_id' => $bonus->payroll_component_id, 'amount' => 1])
            ->assertSessionHasErrors('period');
        $this->delete("/compensation/payroll-inputs/{$bonus->id}")->assertStatus(422);
    }

    public function test_a_loan_is_approved_scheduled_deducted_and_paid_off(): void
    {
        $maker = $this->hr(['loans' => 'edit', 'payroll-approval' => 'edit']);
        $this->signIn($maker)->post('/compensation/loans', ['employee_id' => $this->k->id, 'principal' => 3_000_000, 'tenor' => 3, 'start_period' => '2026-10', 'purpose' => 'Renovasi'])
            ->assertSessionHasNoErrors();
        $loan = Loan::sole();
        $this->assertSame(1_000_000, $loan->installment);
        $this->post("/compensation/loans/{$loan->id}/approve")->assertForbidden(); // own entry
        $this->signIn($this->admin())->post("/compensation/loans/{$loan->id}/approve")->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertSame('active', $loan->status);
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $loan->installments->pluck('period')->all());
        $this->assertSame(3_000_000, (int) $loan->installments->sum('amount'));

        // October's slip deducts the first installment after tax.
        $before = $this->slip();
        $this->assertSame(1_000_000, collect($before['deductions'])->firstWhere('code', 'loan')['amount']);

        $this->post('/compensation/payroll', ['period' => '2026-10']);
        $run = PayrollRun::sole();
        $this->post("/compensation/payroll/{$run->id}/calculate");
        $this->post("/compensation/payroll/{$run->id}/submit");
        $this->post("/compensation/payroll/{$run->id}/approve");
        $first = $loan->installments()->where('sequence', 1)->first();
        $this->assertSame('deducted', $first->status);
        $this->assertNotNull($first->payslip_id);

        // November put off a month: it and December move on.
        $nov = $loan->installments()->where('sequence', 2)->first();
        $this->post("/compensation/loans/installments/{$nov->id}/postpone")->assertSessionHasNoErrors();
        $this->assertSame(['2026-10', '2026-12', '2027-01'], $loan->installments()->get()->pluck('period')->all());

        // The rest at once in December's pay.
        $this->post("/compensation/loans/{$loan->id}/pay-off", ['how' => 'payroll', 'period' => '2026-12'])->assertSessionHasNoErrors();
        $left = $loan->installments()->where('status', 'scheduled')->get();
        $this->assertCount(1, $left);
        $this->assertSame(['2026-12', 2_000_000], [$left[0]->period, (int) $left[0]->amount]);

        // Or settled outside pay.
        $this->post("/compensation/loans/{$loan->id}/pay-off", ['how' => 'outside', 'note' => 'Transfer'])->assertSessionHasNoErrors();
        $this->assertSame('paid_off', $loan->fresh()->status);
        $this->assertSame(0, $loan->fresh()->remaining());
    }

    public function test_installments_stay_within_the_cap_and_the_portal_asks_for_loans(): void
    {
        $user = User::create(['name' => $this->k->name, 'email' => uniqid('k').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->k->id, 'is_active' => true]);
        // 50% of the fixed pay (8 jt) = 4 jt: 12 jt over 2 months is 6 jt a month.
        $this->actingAs($user)->post('/self-service/loans', ['principal' => 12_000_000, 'tenor' => 2, 'start_period' => '2026-11'])->assertSessionHasErrors('tenor');
        $this->post('/self-service/loans', ['principal' => 12_000_000, 'tenor' => 6, 'start_period' => '2026-11', 'purpose' => 'Sekolah anak'])->assertSessionHasNoErrors();
        $loan = Loan::sole();
        $this->assertSame(['pending', 'portal'], [$loan->status, $loan->source]);
        $this->get('/self-service/loans')->assertInertia(fn (Assert $p) => $p->component('SelfService/Loans')->has('loans', 1)->where('has_pending', true));
        $this->post('/self-service/loans', ['principal' => 1_000_000, 'tenor' => 2, 'start_period' => '2026-11'])->assertStatus(422);

        $approver = User::where('role', User::ROLE_ADMIN)->first();
        $this->assertTrue($approver->notifications()->where('data', 'like', '%Pengajuan Pinjaman%')->exists());

        $this->delete("/self-service/loans/{$loan->id}")->assertRedirect();
        $this->assertSame(0, Loan::count());
    }
}
