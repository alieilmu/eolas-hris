<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use App\Models\Position;
use App\Models\Employee;
use App\Models\WorkforcePlanSetting;
use App\Models\WorkforcePlan;
use App\Models\WorkforcePlanRevision;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Access\Menu;
use App\Support\WorkforcePlanning\WorkforcePlanning;
use App\Support\WorkforcePlanning\WorkforcePlanExcel;
use Carbon\CarbonImmutable;
use Database\Seeders\OrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WorkforcePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 09:00:00');
        CarbonImmutable::setTestNow('2026-10-08 09:00:00');
        $this->seed();
        WorkforcePlanSetting::forget();
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

    private function location(string $name): WorkLocation
    {
        return WorkLocation::where('name', $name)->firstOrFail();
    }

    /** An admin of one site who may change the plan but not approve it. */
    private function siteAdmin(WorkLocation $site): User
    {
        return User::create([
            'name' => 'Admin Site', 'email' => 'site@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => ['workforce-planning' => Menu::EDIT, 'recruitment' => Menu::EDIT], 'work_location_ids' => [$site->id], 'is_active' => true,
        ]);
    }

    private function plan(WorkLocation $l, string $code = '2026-Q4'): WorkforcePlan
    {
        return WorkforcePlanning::plan($l, WorkforcePlanning::parseCode($code));
    }

    public function test_the_page_counts_actual_and_carries_the_plan_to_the_next_quarter(): void
    {
        $ho = $this->location('Head Office');
        $stf = Position::findOrFail(OrgStructureSeeder::$created['STF-FIN']);

        $this->actingAs($this->hr())->get('/core-hr/workforce-planning')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('CoreHr/WorkforcePlanning')
            ->where('mode', 'location')
            ->where('location.name', 'Head Office')
            ->where('period.code', '2026-Q4')
            ->where('all_sites', true)
            ->where('can_approve', true)
            ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['rows'])->firstWhere('position_id', $stf->id)['actual'] === 2));

        // HR (an approver) sets the plan: saved straight away, kept as history.
        $q4 = $this->plan($ho);
        $this->put("/core-hr/workforce-planning/{$q4->id}", ['items' => [['position_id' => $stf->id, 'plan' => 3, 'promotions' => 1, 'fulfilment_month' => 11, 'source' => 'external']]])->assertSessionHasNoErrors();
        $this->assertSame(1, WorkforcePlanRevision::where(['status' => 'approved', 'is_direct' => true])->count());
        $row = collect(WorkforcePlanning::table($q4->fresh())['groups'])->flatMap(fn ($g) => $g['rows'])->firstWhere('position_id', $stf->id);
        // Gap = Actual − (Plan + Promosi) = 2 − (3 + 1).
        $this->assertSame(-2, $row['gap']);

        // Q1 2027 starts from Q4: the plan carries on, the movements do not.
        $q1 = $this->plan($ho, '2027-Q1');
        $item = $q1->items()->where('position_id', $stf->id)->firstOrFail();
        $this->assertSame([3, 0, 'external', $q4->id], [$item->plan, $item->promotions, $item->source, $q1->source_plan_id]);

        $this->get('/core-hr/workforce-planning?location=all')->assertInertia(fn (Assert $page) => $page->where('mode', 'all')->has('sites', 3));
    }

    public function test_retirement_and_long_sickness_count_by_themselves(): void
    {
        $rantau = $this->location('Site Rantau');
        $spv = Position::findOrFail(OrgStructureSeeder::$created['SPV-MTC']); // Joko Santoso, retiring in 75 days
        $mkn = Position::findOrFail(OrgStructureSeeder::$created['MKN-MTC']); // Rudi Hartono
        Employee::where('position_id', $mkn->id)->firstOrFail()->profile->update(['long_sick_start_date' => '2026-09-01', 'long_sick_notes' => 'Rawat inap']);

        $rows = collect(WorkforcePlanning::table($this->plan($rantau))['groups'])->flatMap(fn ($g) => $g['rows'])->keyBy('position_id');
        $this->assertSame([1, 1, 12], [$rows[$spv->id]['retirements'], $rows[$spv->id]['actual'], $rows[$spv->id]['movement_month']]);
        // Gap = 1 − (0 + 1 pensiun).
        $this->assertSame(0, $rows[$spv->id]['gap']);
        $this->assertSame(1, $rows[$mkn->id]['sick']);
        $this->assertSame('Rawat inap', $rows[$mkn->id]['sick_list'][0]['description']);

        // A number typed in replaces the count.
        $this->actingAs($this->hr())->put('/core-hr/workforce-planning/'.$this->plan($rantau)->id, ['items' => [['position_id' => $spv->id, 'retirements' => 0]]])->assertSessionHasNoErrors();
        $rows = collect(WorkforcePlanning::table($this->plan($rantau))['groups'])->flatMap(fn ($g) => $g['rows'])->keyBy('position_id');
        $this->assertSame([0, true, 1], [$rows[$spv->id]['retirements'], $rows[$spv->id]['retirements_manual'], $rows[$spv->id]['gap']]);
    }

    public function test_a_site_sends_revisions_that_ho_approves(): void
    {
        $rantau = $this->location('Site Rantau');
        $admin = $this->siteAdmin($rantau);
        $mkn = Position::findOrFail(OrgStructureSeeder::$created['MKN-MTC']);
        $plan = $this->plan($rantau);

        // Only its own location.
        $this->actingAs($admin)->get('/core-hr/workforce-planning')->assertInertia(fn (Assert $page) => $page->where('location.name', 'Site Rantau')->where('all_sites', false)->where('can_approve', false));
        $this->get('/core-hr/workforce-planning?location=all')->assertInertia(fn (Assert $page) => $page->where('mode', 'location'));
        $this->put('/core-hr/workforce-planning/'.$this->plan($this->location('Head Office'))->id, ['items' => [['position_id' => $mkn->id, 'plan' => 1]]])->assertForbidden();

        $this->put("/core-hr/workforce-planning/{$plan->id}", ['items' => [['position_id' => $mkn->id, 'plan' => 4, 'description' => 'Unit baru masuk']], 'notes' => 'Tambah 2 unit HD'])->assertSessionHasNoErrors();
        $revision = WorkforcePlanRevision::where('status', 'pending')->firstOrFail();
        $this->assertSame(['plan' => [0, 4], 'description' => [null, 'Unit baru masuk']], $revision->changes[(string) $mkn->id]);
        $this->assertSame(0, $plan->items()->count()); // the plan waits
        $this->assertSame('Revisi Man Power Planning', $this->hr()->unreadNotifications()->firstOrFail()->data['title']);
        // One at a time.
        $this->put("/core-hr/workforce-planning/{$plan->id}", ['items' => [['position_id' => $mkn->id, 'plan' => 5]]])->assertSessionHasErrors('items');
        $this->post("/core-hr/workforce-planning/revision/{$revision->id}/verification", ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($this->hr())->post("/core-hr/workforce-planning/revision/{$revision->id}/verification", ['decision' => 'reject'])->assertSessionHasErrors('notes');
        $this->post("/core-hr/workforce-planning/revision/{$revision->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(4, $plan->items()->where('position_id', $mkn->id)->value('plan'));
        $this->assertSame(0, $this->hr()->unreadNotifications()->count());
        $this->assertSame('Revisi MPP disetujui', $admin->unreadNotifications()->firstOrFail()->data['title']);
    }

    public function test_a_short_position_opens_a_vacancy_that_hires_into_it(): void
    {
        $rantau = $this->location('Site Rantau');
        $mkn = Position::findOrFail(OrgStructureSeeder::$created['MKN-MTC']);
        $plan = $this->plan($rantau);
        $this->actingAs($this->hr())->put("/core-hr/workforce-planning/{$plan->id}", ['items' => [['position_id' => $mkn->id, 'plan' => 3]]]);

        $this->post("/core-hr/workforce-planning/{$plan->id}/careers/{$mkn->id}", ['requirements' => 2])->assertRedirect();
        $form = JobPosting::latest('id')->firstOrFail();
        $this->assertSame([$mkn->id, 'Site Rantau', false, 7], [$form->position_id, $form->location, $form->is_open, $form->fields()->count()]);
        $row = collect(WorkforcePlanning::table($plan)['groups'])->flatMap(fn ($g) => $g['rows'])->firstWhere('position_id', $mkn->id);
        $this->assertSame($form->id, $row['careers']['form_id']);
        // 2 short × the first step of the mechanics' golongan (G0) in the 2026 Skala Upah.
        $this->assertSame(['G0', 2 * 4095936], [$row['grade'], $row['cost']]);
    }

    public function test_the_plan_goes_out_to_excel_and_comes_back(): void
    {
        $ho = $this->location('Head Office');
        $plan = $this->plan($ho);
        $stf = Position::findOrFail(OrgStructureSeeder::$created['STF-FIN']);
        $this->actingAs($this->hr())->get("/core-hr/workforce-planning/{$plan->id}/export")->assertOk()->assertDownload('workforce_planning-head-office-2026-q4.xlsx');

        // HR fills Plan 5 and a promotion in October in the exported sheet.
        $book = WorkforcePlanExcel::build($plan->fresh('workLocation'));
        $ws = $book->getActiveSheet();
        for ($r = 6; $r <= $ws->getHighestDataRow(); $r++) {
            if ($ws->getCell("B{$r}")->getValue() === $stf->name) {
                $ws->setCellValue("C{$r}", 5);
                $ws->setCellValue("E{$r}", 1);
                $ws->setCellValue([12 + 9, $r], 1); // month 10 of "Waktu Promosi…"
            }
        }
        $ws->setCellValue('B'.($ws->getHighestDataRow() + 1), 'JABATAN YANG TIDAK ADA');
        $path = tempnam(sys_get_temp_dir(), 'workforce_planning').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'workforce_planning.xlsx', null, null, true);

        $preview = $this->post("/core-hr/workforce-planning/{$plan->id}/import", ['file' => $file])->assertOk()->json();
        $this->assertSame([['field' => 'plan', 'before' => 0, 'after' => 5], ['field' => 'promotions', 'before' => 0, 'after' => 1], ['field' => 'movement_month', 'before' => '—', 'after' => 10]],
            collect($preview['changes'])->firstWhere('position_id', $stf->id)['fields']);
        $this->post("/core-hr/workforce-planning/{$plan->id}/import", ['file' => $file, 'save' => 1])->assertSessionHasNoErrors();
        $this->assertSame(5, $plan->items()->where('position_id', $stf->id)->value('plan'));
    }

    public function test_master_data_switches_to_semesters(): void
    {
        $this->actingAs($this->hr())->put('/core-hr/settings/workforce-planning', ['period' => 'semiannual', 'grade_by_job_level' => []])->assertSessionHasNoErrors();
        WorkforcePlanSetting::forget();
        $this->get('/core-hr/workforce-planning')->assertInertia(fn (Assert $page) => $page->where('period.code', '2026-S2'));
        $this->assertTrue(WorkforcePlan::where(['type' => 'semiannual', 'period_number' => 2])->exists());
    }
}
