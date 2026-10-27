<?php

namespace Tests\Feature\Compensation;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Grade;
use App\Models\JobLevelAllowance;
use App\Models\OvertimeRecord;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\SalaryScaleAmount;
use App\Models\SalaryScalePeriod;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Support\Payroll\Pph21;
use App\Support\Payroll\Salary;
use App\Support\Security\TwoFactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Payroll phase 6: bank file, BPJS, PPh 21 for Coretax, journal, and the 1721-A1. */
class PayrollReportsTest extends TestCase
{
    use RefreshDatabase;

    private PayrollRun $run;

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
        $active = Employee::where('status', Employee::STATUS_ACTIVE)->with('profile')->get();
        foreach ($active as $i => $k) {
            $k->profile->update(['bank_name' => $i % 2 ? 'Bank Mandiri' : 'BCA', 'bank_account_number' => '12345'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'hire_date' => '2020-01-01']);
        }
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true]);
        $this->post('/compensation/payroll', ['period' => '2026-10', 'pay_date' => '2026-10-28']);
        $this->run = PayrollRun::sole();
        $this->post("/compensation/payroll/{$this->run->id}/calculate");
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function sheet(string $content, int $index = 0): array
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $content);

        return IOFactory::load($path)->getSheet($index)->toArray(null, true, false, false);
    }

    private function approve(): void
    {
        $this->post("/compensation/payroll/{$this->run->id}/submit");
        $this->post("/compensation/payroll/{$this->run->id}/approve");
    }

    public function test_files_come_only_from_approved_runs(): void
    {
        $this->get("/compensation/payroll/{$this->run->id}/reports/bank")->assertStatus(422);
        $this->approve();
        $this->get("/compensation/payroll/{$this->run->id}/reports/bank?format=generic")->assertOk()->assertDownload('transfer-regular-2026-10.xlsx');
        $this->get("/compensation/payroll/{$this->run->id}/reports/bank?format=nope")->assertNotFound();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'payroll.report_bank')->exists());
    }

    public function test_bank_files_pay_the_take_home_pay_per_bank(): void
    {
        $this->approve();
        $slips = Payslip::where('payroll_run_id', $this->run->id)->with('employee.profile')->get();
        $bca = $slips->filter(fn ($s) => $s->snapshot['bank_name'] === 'BCA');

        $csv = $this->get("/compensation/payroll/{$this->run->id}/reports/bank?format=bca")->streamedContent();
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $csv)));
        $this->assertCount($bca->count(), $rows);
        $this->assertSame((int) $bca->sum('take_home_pay'), array_sum(array_map(fn ($r) => (int) $r[1], $rows)));

        $mandiri = $this->get("/compensation/payroll/{$this->run->id}/reports/bank?format=mandiri")->streamedContent();
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $mandiri)));
        $this->assertSame('P', $lines[0][0]);
        $this->assertSame((int) $slips->filter(fn ($s) => $s->snapshot['bank_name'] === 'Bank Mandiri')->sum('take_home_pay'), (int) $lines[0][4]);

        $generic = $this->sheet($this->get("/compensation/payroll/{$this->run->id}/reports/bank?format=generic")->streamedContent());
        $amounts = array_filter(array_map(fn ($r) => is_numeric($r[0] ?? null) ? (int) $r[6] : null, $generic), fn ($v) => $v !== null);
        $this->assertSame((int) $slips->sum('take_home_pay'), array_sum($amounts));
    }

    public function test_the_journal_balances_and_pph21_lists_every_slip(): void
    {
        $this->approve();
        $rows = $this->sheet($this->get("/compensation/payroll/{$this->run->id}/reports/journal")->streamedContent());
        $body = array_filter($rows, fn ($r) => is_string($r[0] ?? null) && preg_match('/^\d{4}$/', $r[0]));
        $debit = array_sum(array_map(fn ($r) => (int) $r[4], $body));
        $credit = array_sum(array_map(fn ($r) => (int) $r[5], $body));
        $this->assertGreaterThan(0, $debit);
        $this->assertSame($debit, $credit);

        $pph = $this->sheet($this->get("/compensation/payroll/{$this->run->id}/reports/pph21")->streamedContent());
        $data = array_filter($pph, fn ($r) => ($r[8] ?? null) === '21-100-01');
        $this->assertCount(Payslip::where('payroll_run_id', $this->run->id)->count(), $data);
        $this->assertSame((int) Payslip::where('payroll_run_id', $this->run->id)->sum('tax'), array_sum(array_map(fn ($r) => (int) $r[13], $data)));

        $this->get("/compensation/payroll/{$this->run->id}/reports/bpjs")->assertOk()->assertDownload('bpjs-2026-10.xlsx');
    }

    public function test_the_year_adds_up_to_a_1721_a1(): void
    {
        $this->approve();
        $slip = Payslip::where('payroll_run_id', $this->run->id)->firstOrFail();
        $row = \App\Support\Payroll\Reports\AnnualTax::year(2026, [$slip->employee_id])->first();
        $this->assertSame($slip->gross, $row['lines'][8]);
        $this->assertSame(array_sum(array_intersect_key($row['lines'], array_flip([1, 2, 3, 4, 5, 6, 7]))), $row['lines'][8]);
        $this->assertSame($slip->tax_withheld, $row['lines'][20]);
        $this->assertFalse($row['complete']); // December not approved yet
        $this->assertSame(0, $row['difference']); // until then the year's tax is what was withheld

        $this->get('/compensation/payroll-annual?year=2026')->assertInertia(fn (Assert $p) => $p->component('Compensation/PayrollAnnual')
            ->has('rows', Payslip::where('payroll_run_id', $this->run->id)->count()));
        $this->get("/compensation/payroll-annual/{$slip->employee_id}/pdf?year=2026")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/compensation/payroll-annual/export?year=2026')->assertOk()->assertDownload('1721-a1-2026.xlsx');

        // The employee: their own summary, opened with the birth date.
        $user = User::create(['name' => $slip->name, 'email' => uniqid('k').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_EMPLOYEE, 'employee_id' => $slip->employee_id, 'is_active' => true]);
        $this->actingAs($user)->get('/self-service/payslips')->assertInertia(fn (Assert $p) => $p->where('tax_years', [2026]));
        $pdf = $this->get('/self-service/tax-slips/2026/pdf')->assertOk();
        $this->assertStringContainsString('/Encrypt', $pdf->getContent());
        $this->get('/self-service/tax-slips/2025/pdf')->assertNotFound();
    }
}
