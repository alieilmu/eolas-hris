<?php

namespace Tests\Feature\Compensation;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Grade;
use App\Models\JobLevelAllowance;
use App\Models\OvertimeRecord;
use App\Models\PayrollRate;
use App\Models\PayrollSetting;
use App\Models\SalaryScale;
use App\Models\SalaryScaleAmount;
use App\Models\SalaryScalePeriod;
use App\Models\ShiftCode;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Support\Payroll\Bpjs;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\Pph21;
use App\Support\Payroll\Salary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Payroll phase 2: BPJS, PPh21 (TER and the December year-end), potong gapok and the slip simulation. */
class PayrollEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-25 09:00:00');
        $this->seed();
        Salary::flush();
        Pph21::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** An active TK/0 man with NPWP, Gaji Pokok 10.000.000 from January, no allowance, JKK 0,24%. */
    private function employee(): Employee
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $k->profile->update(['gender' => 'Laki-laki', 'marital_status' => 'Belum Menikah', 'spouse_name' => null, 'tax_id_number' => '123456789012345', 'hire_date' => '2020-01-01']);
        $k->profile->children()->delete();
        AttendanceRecord::where('employee_id', $k->id)->delete();
        ShiftSchedule::where('employee_id', $k->id)->delete();
        OvertimeRecord::where('employee_id', $k->id)->delete();
        $k->workLocation->update(['jkk_rate' => 0.24]);
        JobLevelAllowance::updateOrCreate(['job_level_id' => $k->job_level_id], ['amount' => 0]);

        $grade = Grade::create(['grade_group' => 'TEST', 'code' => 'T1', 'sort_order' => 99]);
        $scale = SalaryScale::create(['grade_id' => $grade->id, 'step' => '1', 'sort_order' => 0]);
        $column = SalaryScalePeriod::create(['year' => 2026, 'work_location_id' => null, 'sort_order' => 99]);
        SalaryScaleAmount::create(['salary_scale_id' => $scale->id, 'salary_scale_period_id' => $column->id, 'amount' => 10_000_000]);
        EmployeeSalary::where('employee_id', $k->id)->delete();
        EmployeeSalary::create(['employee_id' => $k->id, 'salary_scale_id' => $scale->id, 'effective_date' => '2026-01-01', 'reason' => 'initial']);

        return $k->fresh();
    }

    private function october(): PayrollPeriod
    {
        return PayrollPeriod::of(2026, 10, PayrollSetting::current()->cutoff_day);
    }

    public function test_ter_brackets_and_the_djp_example(): void
    {
        $r = PayrollRate::forYear(2026);
        $this->assertSame(0.0, Pph21::terRate('A', 5_400_000));
        $this->assertSame(0.25, Pph21::terRate('A', 5_400_001));
        $this->assertSame(2.0, Pph21::terRate('A', 10_000_000));
        $this->assertSame(34.0, Pph21::terRate('C', 2_000_000_000));
        // PMK 168/2023: K/1, gaji 10.000.000 + JKK 0,24% + JKM 0,3% = 10.054.000 → TER B 1,5%.
        $this->assertSame(150_810, Pph21::monthly('B', 10_054_000, $r));
        $this->assertSame(750_000, Pph21::monthly('C', 15_000_000, $r));
        // Without NPWP / NIK: 20% more.
        $this->assertSame(180_972, Pph21::monthly('B', 10_054_000, $r, hasTaxId: false));
    }

    public function test_ptkp_pasal_17_and_the_year_end(): void
    {
        $r = PayrollRate::forYear(2026);
        $this->assertSame(54_000_000, Pph21::ptkp('TK/0', $r));
        $this->assertSame(72_000_000, Pph21::ptkp('K/3', $r));
        $this->assertSame(44_000_000, Pph21::progressive(300_000_000)); // 3 jt + 28,5 jt + 12,5 jt

        // The same K/1 employee in December: 12 × 10.054.000, JHT 2% + JP 1% = 300.000 a month.
        $a = Pph21::annual(120_648_000, 3_600_000, 12, 'K/1', $r, withheld: 11 * 150_810);
        $this->assertSame(6_000_000, $a['position_cost']);
        $this->assertSame(111_048_000, $a['net']);
        $this->assertSame(48_048_000, $a['pkp']);
        $this->assertSame(2_402_400, $a['tax_year']);
        $this->assertSame(743_490, $a['tax']);
    }

    public function test_bpjs_caps_and_participation(): void
    {
        $r = PayrollRate::forYear(2026);
        $b = Bpjs::calculate(15_000_000, $r, 1.74);
        $this->assertSame(['health' => 480_000, 'jkk' => 261_000, 'jkm' => 45_000, 'jht' => 555_000, 'jp' => 210_948], $b['company']);
        $this->assertSame(['health' => 120_000, 'jht' => 300_000, 'jp' => 105_474], $b['employee']);

        $none = Bpjs::calculate(15_000_000, $r, 1.74, health: false, employment: false);
        $this->assertSame(0, array_sum($none['company']) + array_sum($none['employee']));
    }

    public function test_gross_up_allowance_covers_its_own_tax(): void
    {
        $r = PayrollRate::forYear(2026);
        $taxOn = fn (int $gross) => Pph21::monthly('A', $gross, $r);
        $allowance = Pph21::grossUp(10_454_000, $taxOn);
        $this->assertGreaterThan(0, $allowance);
        $this->assertGreaterThanOrEqual($taxOn(10_454_000 + $allowance), $allowance);
    }

    public function test_alpa_and_izin_potong_gapok_cut_the_gaji_pokok_and_the_slip_adds_up(): void
    {
        $k = $this->employee();
        $code = ShiftCode::pluck('id', 'code');
        $this->assertTrue(ShiftCode::where('code', 'IP')->value('deducts_base_salary'));
        $this->assertTrue(ShiftCode::where('code', 'A')->value('deducts_base_salary'));
        $this->assertFalse(ShiftCode::where('code', 'I')->value('deducts_base_salary'));

        // 21 working days on the roster; 2 × ALPA, 1 × Izin Potong Gapok, 1 × Izin (paid).
        for ($d = 1; $d <= 21; $d++) {
            ShiftSchedule::create(['employee_id' => $k->id, 'date' => sprintf('2026-10-%02d', $d), 'shift_code_id' => $code['D']]);
        }
        foreach (['2026-10-05' => 'A', '2026-10-06' => 'A', '2026-10-07' => 'IP', '2026-10-08' => 'I'] as $date => $c) {
            AttendanceRecord::create(['employee_id' => $k->id, 'date' => $date, 'shift_code_id' => $code[$c], 'source' => 'manual']);
        }
        OvertimeRecord::create(['employee_id' => $k->id, 'date' => '2026-10-10', 'day_type' => 'workday', 'start_date' => '2026-10-10 07:00:00',
            'end_date' => '2026-10-10 18:00:00', 'worked_minutes' => 600, 'overtime_minutes' => 120, 'paid_hours' => 2, 'weighted_hours' => 3.5,
            'status' => 'approved', 'source' => 'device']);

        $slip = PayrollCalculator::month($k, $this->october());

        $this->assertSame(['days' => 3, 'divisor' => 21, 'divisor_source' => 'schedule', 'amount' => 1_428_571], array_diff_key($slip['absence'], ['dates' => 0]));
        $this->assertSame(202_312, $slip['overtime']['amount']); // 3,5 × 10.000.000 ÷ 173
        // 10.000.000 − 1.428.571 + 202.312 = 8.773.741; + BPJS Kes 400.000 + JKK 24.000 + JKM 30.000.
        $this->assertSame(9_227_741, $slip['gross']);
        $this->assertSame(161_485, $slip['tax']); // TER A 1,75%
        $this->assertSame(8_773_741, $slip['total_earnings']);
        $this->assertSame(100_000 + 200_000 + 100_000 + 161_485, $slip['total_deductions']);
        $this->assertSame(8_212_256, $slip['take_home_pay']);

        // A fixed divisor instead of the roster.
        PayrollSetting::current()->update(['absence_divisor_mode' => 'fixed', 'absence_divisor_days' => 25]);
        $this->assertSame(1_200_000, PayrollCalculator::month($k, $this->october())['absence']['amount']);
    }

    public function test_december_works_out_the_whole_year(): void
    {
        $k = $this->employee();
        $slip = PayrollCalculator::month($k, PayrollPeriod::of(2026, 12, 31));

        // Each month: 10.000.000 + 454.000 company BPJS = 10.454.000 → TER A 2,5% = 261.350.
        $this->assertSame('annual', $slip['mode']);
        $steps = array_column($slip['tax_steps'], 'amount', 'label');
        $this->assertSame(125_448_000, $steps['Bruto setahun (12 bulan)']);
        $this->assertSame(61_848_000, $steps['PKP (dibulatkan ke ribuan)']);
        $this->assertSame(3_277_200, $steps['PPh21 setahun (Pasal 17)']);
        $this->assertSame(-11 * 261_350, $steps['Sudah dipotong Januari–November']);
        $this->assertSame(402_350, $slip['tax']);
    }

    public function test_net_method_is_borne_by_the_company(): void
    {
        $k = $this->employee();
        $k->payrollProfile()->create(['tax_method' => 'net']);
        $slip = PayrollCalculator::month($k->fresh(), $this->october());

        $this->assertNotContains('PPh21', array_column($slip['deductions'], 'label'));
        $this->assertContains('PPh21 ditanggung perusahaan', array_column($slip['company'], 'label'));
        $this->assertSame(10_000_000 - 400_000, $slip['take_home_pay']);
    }

    public function test_simulation_page_settings_and_profile(): void
    {
        $k = $this->employee();
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $this->get("/compensation/payroll-simulation?employee={$k->id}&period=2026-10")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Compensation/PayrollSimulation')
                ->where('slip.employee.id', $k->id)->where('slip.take_home_pay', 10_000_000 - 400_000 - 261_350)->where('profile.bpjs_health', true));

        $this->put("/compensation/payroll-profile/{$k->id}", ['tax_method' => 'gross_up', 'bpjs_health' => true, 'bpjs_employment' => false])->assertSessionHasNoErrors();
        $this->assertFalse($k->payrollProfile()->value('bpjs_employment'));

        $this->get('/compensation/settings?tab=payroll')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payroll.settings.cutoff_day', 31)->has('payroll.ter.A', 44)->has('payroll.ter.B', 40)->has('payroll.ter.C', 41));
        $this->put('/compensation/settings/payroll', ['cutoff_day' => 20, 'default_tax_method' => 'gross', 'absence_divisor_mode' => 'fixed', 'absence_divisor_days' => 21,
            'jkk' => [$k->work_location_id => 1.74]])->assertSessionHasNoErrors();
        $this->assertSame(20, PayrollSetting::current()->cutoff_day);
        $this->assertSame('2026-09-21', PayrollPeriod::of(2026, 10, 20)->start->toDateString());

        $rates = PayrollRate::forYear(2026)->only([...PayrollRate::RATES, ...PayrollRate::AMOUNTS]);
        $this->put('/compensation/settings/payroll/rates', ['year' => 2027, 'jp_wage_cap' => 11_000_000] + $rates)->assertSessionHasNoErrors();
        $this->assertSame(11_000_000, PayrollRate::forYear(2028)->jp_wage_cap);

        // Only Mangkir / Izin codes can cut the gaji pokok.
        $this->post('/time/settings/shift-codes', ['code' => 'X1', 'type' => 'work', 'deducts_base_salary' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse(ShiftCode::where('code', 'X1')->value('deducts_base_salary'));
    }
}
