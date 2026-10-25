<?php

namespace Tests\Feature\Compensation;

use App\Models\BenefitClaim;
use App\Models\Grade;
use App\Models\Employee;
use App\Models\OpticalBenefitLimit;
use App\Models\MedicalBenefitLimit;
use App\Models\SalaryScale;
use App\Models\SalaryScalePeriod;
use App\Models\JobLevel;
use App\Models\User;
use App\Support\Compensation\AccountingMonth;
use App\Support\Compensation\BenefitEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 09:00:00');
        CarbonImmutable::setTestNow('2026-10-03 09:00:00');
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** An active employee of a SubGroup, made single (TK/0) or married with $anak children. */
    private function employee(string $jobLevel, bool $kawin = false, int $children = 0, ?string $grade = null): Employee
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)
            ->whereHas('jobLevel', fn ($q) => $q->where('name', $jobLevel))
            ->whereNotIn('id', $this->used ?? [])
            ->firstOrFail();
        $this->used[] = $k->id;
        $k->profile->update(['marital_status' => $kawin ? 'Menikah' : 'Belum Menikah', 'spouse_name' => $kawin ? 'Siti' : null, ...($grade ? ['grade' => $grade] : [])]);
        $k->profile->children()->delete();
        for ($i = 1; $i <= $children; $i++) {
            $k->profile->children()->create(['sort_order' => $i, 'name' => "Anak {$i} {$k->name}"]);
        }

        return $k->fresh(['jobLevel', 'profile.children']);
    }

    private array $used = [];

    private function claim(Employee $k, array $items, string $date = '2026-10-02', ?int $id = null): TestResponse
    {
        $data = ['employee_id' => $k->id, 'date' => $date, 'provider_name' => 'Ugang Sayu', 'attachment' => $id ? [] : [UploadedFile::fake()->image('receipt_doc.jpg')], 'items' => $items];

        return $id ? $this->put("/compensation/claims/{$id}", $data) : $this->post('/compensation/claims', $data);
    }

    private function item(string $type, int $amount, string $beneficiary = 'employee', ?int $children = null): array
    {
        return ['type' => $type, 'beneficiary' => $beneficiary, 'employee_child_id' => $children, 'amount' => $amount];
    }

    // --- master data & rules ---------------------------------------------------------------

    public function test_master_data_is_seeded_from_the_tables(): void
    {
        $this->assertSame(63, Grade::count());
        $this->assertSame(['NONOM', 'OPERATOR', 'MEKANIK', 'WELDER', 'STAFF'], Grade::orderBy('sort_order')->pluck('grade_group')->unique()->values()->all());
        $this->assertSame(72, SalaryScale::count());
        $step1 = SalaryScale::with('value')->where('step', 'Step 1')->firstOrFail();
        $this->assertSame('1A', $step1->grade->code);
        $this->assertEqualsCanonicalizing([3850000, 4067140], $step1->value->pluck('amount')->all());

        $this->assertSame([[1750000, 2250000], [2500000, 3750000], [3250000, 5250000], [5800000, 5800000], [7550000, 7550000], [9550000, 9550000], [11000000, 11000000]],
            MedicalBenefitLimit::orderBy('sort_order')->get()->map(fn ($p) => [$p->single, $p->family])->all());
        $this->assertSame([625000, 800000, 1000000, 1125000, 1250000, 1500000, 2000000], OpticalBenefitLimit::orderBy('sort_order')->pluck('frame')->all());
        // Every SubGroup but BOD has a plafon row.
        $mapped = MedicalBenefitLimit::all()->flatMap->job_level_ids->all();
        $this->assertSame(['BOD'], JobLevel::whereNotIn('id', $mapped)->pluck('name')->all());

        $this->get('/compensation/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Compensation/Settings')
            ->has('grades', 63)
            ->has('period_column', 2)
            ->where('period_column.0.label', 'Tahun 2025'));
    }

    public function test_book_month_closes_on_the_8th_and_the_plafon_year_follows_it(): void
    {
        $this->assertSame('2026-10', AccountingMonth::of(CarbonImmutable::parse('2026-10-08')));
        $this->assertSame('2026-11', AccountingMonth::of(CarbonImmutable::parse('2026-10-09')));
        $this->assertSame('2027-01', AccountingMonth::of(CarbonImmutable::parse('2026-12-09')));
        $this->assertSame(2027, AccountingMonth::year(AccountingMonth::of(CarbonImmutable::parse('2026-12-31'))));
        $this->assertSame('09.09.2026 – 08.10.2026', AccountingMonth::span('2026-10'));
    }

    public function test_plafon_follows_subgroup_and_family_status(): void
    {
        $e = BenefitEntitlement::shared();
        $lajang = $this->employee('Operator', grade: 'D2J');
        $this->assertSame('TK/0', BenefitEntitlement::statusPtkp($lajang));
        $this->assertSame(1750000, $e->medicalBenefitLimit($lajang));
        $this->assertNull($e->warning($lajang));

        $family = $this->employee('Operator', kawin: true, children: 2, grade: '3A');
        $this->assertSame('K/2', BenefitEntitlement::statusPtkp($family));
        $this->assertSame(2250000, $e->medicalBenefitLimit($family));
        $this->assertSame('Golongan 3A di luar rentang plafon L1 untuk SubGroup Operator.', $e->warning($family));
        $this->assertCount(4, BenefitEntitlement::members($family));

        // Not married but with a child: keluarga.
        $single = $this->employee('Section Head Site', children: 1, grade: '4C');
        $this->assertSame('TK/1', BenefitEntitlement::statusPtkp($single));
        $this->assertSame(5800000, $e->medicalBenefitLimit($single));

        // The profile shows it, computed.
        $this->get("/core-hr/employees/{$family->id}/profile")->assertInertia(fn (Assert $page) => $page
            ->where('computed.ptkp_status', 'K/2')
            ->where('computed.medical_benefit_limit', fn ($t) => str_starts_with($t, 'Rp 2.250.000 / tahun (L1 · Keluarga)'))
            ->where('sections', fn ($sections) => collect($sections)->flatMap(fn ($s) => $s['fields'] ?? [])->firstWhere('key', 'grade')['options'][0] === '1A'));
    }

    // --- deklarasi ---------------------------------------------------------------------------

    public function test_deklarasi_pengobatan_within_the_family_plafon(): void
    {
        $k = $this->employee('Operator', kawin: true, children: 1, grade: 'D2J');
        $children = $k->profile->children->first();

        $this->claim($k, [$this->item('medical', 215000), $this->item('treatment', 100000, 'spouse'), $this->item('medical', 50000, 'children', $children->id)])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', "Deklarasi DKL/2026/10/0001 {$k->name} disimpan (bulan buku Oktober 2026).");
        $d = BenefitClaim::firstOrFail();
        $this->assertSame(['2026-10', 2026], [$d->accounting_month, $d->benefit_year]);

        $this->getJson("/compensation/employee/{$k->id}/benefit-limit?date=2026-10-02")->assertOk()
            ->assertJsonPath('medical.benefit_limit', 2250000)
            ->assertJsonPath('medical.used', 365000)
            ->assertJsonPath('medical.remaining', 1885000)
            ->assertJsonPath('ptkp_status', 'K/1')
            ->assertJsonPath('accounting_month.label', 'Oktober 2026');

        // Over what is left: refused.
        $this->claim($k, [$this->item('medical', 1900000)])
            ->assertSessionHasErrors(['items' => 'Plafon Pengobatan 2026 (K/1) tersisa Rp 1.885.000 dari Rp 2.250.000; deklarasi ini Rp 1.900.000.']);
        // A date after the 8th of December books to January: the next year's plafon.
        $this->claim($k, [$this->item('medical', 1900000)], '2026-12-10')->assertSessionHasNoErrors();
        $this->assertSame(['2027-01', 2027], [BenefitClaim::latest('id')->first()->accounting_month, BenefitClaim::latest('id')->first()->benefit_year]);
        $this->assertSame('DKL/2027/01/0001', BenefitClaim::latest('id')->first()->claim_number);

        // Editing counts the deklarasi only once; deleting frees the plafon.
        $this->claim($k, [$this->item('medical', 2250000)], '2026-10-02', $d->id)->assertSessionHasNoErrors();
        $this->claim($k, [$this->item('medical', 1)])->assertSessionHasErrors('items');
        $this->delete("/compensation/claims/{$d->id}")->assertSessionHasNoErrors();
        $this->claim($k, [$this->item('medical', 1)])->assertSessionHasNoErrors();
    }

    public function test_hr_enters_any_period_while_the_portal_is_dated_today(): void
    {
        $k = $this->employee('Operator');

        // HR, from the signed form: an earlier book month.
        $this->claim($k, [$this->item('medical', 100000)], '2026-05-10')->assertSessionHasNoErrors();
        $this->assertSame(['2026-05-10', '2026-06'], [BenefitClaim::latest('id')->first()->date->toDateString(), BenefitClaim::latest('id')->first()->accounting_month]);

        // The employee: whatever date is sent, the deklarasi is dated the day it is sent.
        $user = User::create([
            'name' => $k->name, 'email' => uniqid('k').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $k->id, 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->actingAs($user)->get('/self-service')->assertInertia(fn (Assert $page) => $page->where('today', '2026-10-03'));
        $this->actingAs($user)->post('/self-service/claim', [
            'date' => '2026-05-10', 'items' => [$this->item('medical', 50000)], 'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')],
        ])->assertSessionHasNoErrors();
        $d = BenefitClaim::latest('id')->first();
        $this->assertSame(['2026-10-03', '2026-10', 'self_service'], [$d->date->toDateString(), $d->accounting_month, $d->source]);
    }

    public function test_kacamata_per_member_frame_every_two_years_lens_every_year(): void
    {
        $k = $this->employee('Officer HO/ Group Leader', kawin: false, children: 1, grade: '4A');
        $children = $k->profile->children->first();

        // Not married: no spouse; a child must be one of theirs.
        $this->claim($k, [$this->item('lens', 100000, 'spouse')])->assertSessionHasErrors(['items' => 'Status perkawinan karyawan bukan Menikah, jadi tidak ada klaim untuk Istri / Suami.']);
        $this->claim($k, [$this->item('lens', 100000, 'children', 999999)])->assertSessionHasErrors('items');

        $this->claim($k, [$this->item('frame', 1000000), $this->item('lens', 1000000), $this->item('lens', 400000, 'children', $children->id)])->assertSessionHasNoErrors();
        $this->claim($k, [$this->item('lens', 700000, 'children', $children->id)])
            ->assertSessionHasErrors(['items' => "Kacamata – Lensa {$children->name} (Anak 1) tersisa Rp 600.000 dari Rp 1.000.000; deklarasi ini Rp 700.000."]);

        // Next year: lenses again, but the frame only the year after.
        $this->claim($k, [$this->item('lens', 1000000)], '2027-02-01')->assertSessionHasNoErrors();
        $this->claim($k, [$this->item('frame', 500000)], '2027-02-01')
            ->assertSessionHasErrors(['items' => "Kacamata – Bingkai {$k->name} (Karyawan) tersisa Rp 0 dari Rp 1.000.000; deklarasi ini Rp 500.000. Bingkai terakhir diklaim Oktober 2026; bingkai bisa diklaim lagi mulai tahun 2028."]);
        $this->claim($k, [$this->item('frame', 500000)], '2028-02-01')->assertSessionHasNoErrors();
    }

    public function test_monitoring_lists_plafon_claims_per_month_and_what_is_left(): void
    {
        $k = $this->employee('Operator', kawin: true, children: 1, grade: 'D2J');
        $this->claim($k, [$this->item('medical', 200000)], '2026-09-20'); // book month October
        $this->claim($k, [$this->item('medical', 300000), $this->item('lens', 500000, 'spouse')], '2026-10-02');

        $this->get('/compensation/benefit-balances?year=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Compensation/BenefitBalances')
            ->has('months', 12)
            ->where('current_month', '2026-10')
            ->where('medical', fn ($rows) => ($r = collect($rows)->firstWhere('id', $k->id))
                && $r['benefit_limit'] === 2250000 && $r['used'] === 500000 && $r['remaining'] === 1750000
                && (array) $r['month'] === ['2026-10' => 500000] && $r['ptkp_status'] === 'K/1')
            ->where('optical', fn ($rows) => ($r = collect($rows)->firstWhere('key', "{$k->id}:spouse"))
                && $r['lens'] === 500000 && $r['lens_remaining'] === 125000 && $r['members'] === 'Siti'));
    }

    // --- master edits ------------------------------------------------------------------------

    public function test_master_edits_follow_through(): void
    {
        $k = $this->employee('Operator', grade: 'D2J');
        $d2j = Grade::where('code', 'D2J')->firstOrFail();

        // Renaming a golongan renames it on the employees holding it; one in use cannot go.
        $this->put("/compensation/settings/grade/{$d2j->id}", ['grade_group' => 'OPERATOR', 'code' => 'd2j-x'])->assertSessionHasNoErrors();
        $this->assertSame('D2J-X', $k->profile->fresh()->grade);
        $this->delete("/compensation/settings/grade/{$d2j->id}")->assertSessionHasErrors('code');
        $this->post('/compensation/settings/grade', ['grade_group' => 'driver', 'code' => 'DR1'])->assertSessionHasNoErrors();
        $this->assertSame('DRIVER', Grade::where('code', 'DR1')->value('grade_group'));

        // A SubGroup sits in one plafon row only.
        $operator = JobLevel::where('name', 'Operator')->value('id');
        $l2 = MedicalBenefitLimit::where('layer', 'L2')->firstOrFail();
        $this->put("/compensation/settings/benefit-limit/medical/{$l2->id}", [
            'layer' => 'L2', 'grade_ids' => $l2->grade_ids, 'job_level_ids' => [...$l2->job_level_ids, $operator],
            'single' => 1, 'family' => 2,
        ])->assertSessionHasErrors('job_level_ids');

        // Changing the plafon changes the employee's entitlement straight away.
        $l1 = MedicalBenefitLimit::where('layer', 'L1')->firstOrFail();
        $this->put("/compensation/settings/benefit-limit/medical/{$l1->id}", [
            'layer' => 'L1', 'grade_ids' => $l1->grade_ids, 'job_level_ids' => $l1->job_level_ids, 'single' => 1800000, 'family' => 2300000,
        ])->assertSessionHasNoErrors();
        $this->getJson("/compensation/employee/{$k->id}/benefit-limit")->assertJsonPath('medical.benefit_limit', 1800000);

        // Skala upah: a new column and a value in it.
        $this->post('/compensation/settings/salary-scale-periods', ['year' => 2027])->assertSessionHasNoErrors();
        $this->post('/compensation/settings/salary-scale-periods', ['year' => 2027])->assertSessionHasErrors('year');
        $period_column = SalaryScalePeriod::where('year', 2027)->firstOrFail();
        $row = SalaryScale::where('step', 'Step 1')->firstOrFail();
        $this->put("/compensation/settings/salary-scale/{$row->id}", ['grade_id' => $row->grade_id, 'step' => 'Step 1', 'value' => [$period_column->id => 4200000]])->assertSessionHasNoErrors();
        $this->assertSame(4200000, $row->value()->where('salary_scale_period_id', $period_column->id)->value('amount'));
    }

    public function test_viewers_are_kept_out(): void
    {
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());
        $this->get('/compensation/claims')->assertForbidden();
        $this->get('/compensation/benefit-balances')->assertForbidden();
        $this->get('/compensation/settings')->assertForbidden();
    }
}
