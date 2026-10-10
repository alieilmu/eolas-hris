<?php

namespace Tests\Feature;

use App\Models\ApplicationForm;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\MppAturan;
use App\Models\MppPlan;
use App\Models\MppRevisi;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Access\Menu;
use App\Support\Mpp\Mpp;
use App\Support\Mpp\MppExcel;
use Carbon\CarbonImmutable;
use Database\Seeders\OrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 09:00:00');
        CarbonImmutable::setTestNow('2026-10-08 09:00:00');
        $this->seed();
        MppAturan::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@eloas.local')->firstOrFail();
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
            'permissions' => ['mpp' => Menu::EDIT, 'recruitment' => Menu::EDIT], 'work_location_ids' => [$site->id], 'is_active' => true,
        ]);
    }

    private function plan(WorkLocation $l, string $kode = '2026-Q4'): MppPlan
    {
        return Mpp::plan($l, Mpp::parseKode($kode));
    }

    public function test_the_page_counts_actual_and_carries_the_plan_to_the_next_quarter(): void
    {
        $ho = $this->location('Head Office');
        $stf = Jabatan::findOrFail(OrgStructureSeeder::$created['STF-FIN']);

        $this->actingAs($this->hr())->get('/manpower/mpp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Manpower/Mpp')
            ->where('mode', 'lokasi')
            ->where('lokasi.name', 'Head Office')
            ->where('periode.kode', '2026-Q4')
            ->where('all_sites', true)
            ->where('can_approve', true)
            ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['rows'])->firstWhere('jabatan_id', $stf->id)['actual'] === 2));

        // HR (an approver) sets the plan: saved straight away, kept as history.
        $q4 = $this->plan($ho);
        $this->put("/manpower/mpp/{$q4->id}", ['items' => [['jabatan_id' => $stf->id, 'plan' => 3, 'promosi' => 1, 'bulan_penuhi' => 11, 'sumber' => 'eksternal']]])->assertSessionHasNoErrors();
        $this->assertSame(1, MppRevisi::where(['status' => 'disetujui', 'langsung' => true])->count());
        $row = collect(Mpp::table($q4->fresh())['groups'])->flatMap(fn ($g) => $g['rows'])->firstWhere('jabatan_id', $stf->id);
        // Gap = Actual − (Plan + Promosi) = 2 − (3 + 1).
        $this->assertSame(-2, $row['gap']);

        // Q1 2027 starts from Q4: the plan carries on, the movements do not.
        $q1 = $this->plan($ho, '2027-Q1');
        $item = $q1->items()->where('jabatan_id', $stf->id)->firstOrFail();
        $this->assertSame([3, 0, 'eksternal', $q4->id], [$item->plan, $item->promosi, $item->sumber, $q1->dari_plan_id]);

        $this->get('/manpower/mpp?lokasi=semua')->assertInertia(fn (Assert $page) => $page->where('mode', 'semua')->has('sites', 3));
    }

    public function test_retirement_and_long_sickness_count_by_themselves(): void
    {
        $rantau = $this->location('Site Rantau');
        $spv = Jabatan::findOrFail(OrgStructureSeeder::$created['SPV-MTC']); // Joko Santoso, retiring in 75 days
        $mkn = Jabatan::findOrFail(OrgStructureSeeder::$created['MKN-MTC']); // Rudi Hartono
        Karyawan::where('jabatan_id', $mkn->id)->firstOrFail()->profile->update(['sakit_mulai' => '2026-09-01', 'sakit_keterangan' => 'Rawat inap']);

        $rows = collect(Mpp::table($this->plan($rantau))['groups'])->flatMap(fn ($g) => $g['rows'])->keyBy('jabatan_id');
        $this->assertSame([1, 1, 12], [$rows[$spv->id]['pensiun'], $rows[$spv->id]['actual'], $rows[$spv->id]['bulan_pindah']]);
        // Gap = 1 − (0 + 1 pensiun).
        $this->assertSame(0, $rows[$spv->id]['gap']);
        $this->assertSame(1, $rows[$mkn->id]['sakit']);
        $this->assertSame('Rawat inap', $rows[$mkn->id]['sakit_list'][0]['keterangan']);

        // A number typed in replaces the count.
        $this->actingAs($this->hr())->put('/manpower/mpp/'.$this->plan($rantau)->id, ['items' => [['jabatan_id' => $spv->id, 'pensiun' => 0]]])->assertSessionHasNoErrors();
        $rows = collect(Mpp::table($this->plan($rantau))['groups'])->flatMap(fn ($g) => $g['rows'])->keyBy('jabatan_id');
        $this->assertSame([0, true, 1], [$rows[$spv->id]['pensiun'], $rows[$spv->id]['pensiun_manual'], $rows[$spv->id]['gap']]);
    }

    public function test_a_site_sends_revisions_that_ho_approves(): void
    {
        $rantau = $this->location('Site Rantau');
        $admin = $this->siteAdmin($rantau);
        $mkn = Jabatan::findOrFail(OrgStructureSeeder::$created['MKN-MTC']);
        $plan = $this->plan($rantau);

        // Only its own location.
        $this->actingAs($admin)->get('/manpower/mpp')->assertInertia(fn (Assert $page) => $page->where('lokasi.name', 'Site Rantau')->where('all_sites', false)->where('can_approve', false));
        $this->get('/manpower/mpp?lokasi=semua')->assertInertia(fn (Assert $page) => $page->where('mode', 'lokasi'));
        $this->put('/manpower/mpp/'.$this->plan($this->location('Head Office'))->id, ['items' => [['jabatan_id' => $mkn->id, 'plan' => 1]]])->assertForbidden();

        $this->put("/manpower/mpp/{$plan->id}", ['items' => [['jabatan_id' => $mkn->id, 'plan' => 4, 'keterangan' => 'Unit baru masuk']], 'catatan' => 'Tambah 2 unit HD'])->assertSessionHasNoErrors();
        $revisi = MppRevisi::where('status', 'diajukan')->firstOrFail();
        $this->assertSame(['plan' => [0, 4], 'keterangan' => [null, 'Unit baru masuk']], $revisi->perubahan[(string) $mkn->id]);
        $this->assertSame(0, $plan->items()->count()); // the plan waits
        $this->assertSame('Revisi Man Power Planning', $this->hr()->unreadNotifications()->firstOrFail()->data['judul']);
        // One at a time.
        $this->put("/manpower/mpp/{$plan->id}", ['items' => [['jabatan_id' => $mkn->id, 'plan' => 5]]])->assertSessionHasErrors('items');
        $this->post("/manpower/mpp/revisi/{$revisi->id}/verifikasi", ['keputusan' => 'setujui'])->assertForbidden();

        $this->actingAs($this->hr())->post("/manpower/mpp/revisi/{$revisi->id}/verifikasi", ['keputusan' => 'tolak'])->assertSessionHasErrors('catatan');
        $this->post("/manpower/mpp/revisi/{$revisi->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();
        $this->assertSame(4, $plan->items()->where('jabatan_id', $mkn->id)->value('plan'));
        $this->assertSame(0, $this->hr()->unreadNotifications()->count());
        $this->assertSame('Revisi MPP disetujui', $admin->unreadNotifications()->firstOrFail()->data['judul']);
    }

    public function test_a_short_position_opens_a_vacancy_that_hires_into_it(): void
    {
        $rantau = $this->location('Site Rantau');
        $mkn = Jabatan::findOrFail(OrgStructureSeeder::$created['MKN-MTC']);
        $plan = $this->plan($rantau);
        $this->actingAs($this->hr())->put("/manpower/mpp/{$plan->id}", ['items' => [['jabatan_id' => $mkn->id, 'plan' => 3]]]);

        $this->post("/manpower/mpp/{$plan->id}/lowongan/{$mkn->id}", ['kebutuhan' => 2])->assertRedirect();
        $form = ApplicationForm::latest('id')->firstOrFail();
        $this->assertSame([$mkn->id, 'Site Rantau', false, 7], [$form->jabatan_id, $form->lokasi, $form->is_open, $form->fields()->count()]);
        $row = collect(Mpp::table($plan)['groups'])->flatMap(fn ($g) => $g['rows'])->firstWhere('jabatan_id', $mkn->id);
        $this->assertSame($form->id, $row['lowongan']['form_id']);
        // 2 short × the first step of the mechanics' golongan (G0) in the 2026 Skala Upah.
        $this->assertSame(['G0', 2 * 4095936], [$row['golongan'], $row['biaya']]);
    }

    public function test_the_plan_goes_out_to_excel_and_comes_back(): void
    {
        $ho = $this->location('Head Office');
        $plan = $this->plan($ho);
        $stf = Jabatan::findOrFail(OrgStructureSeeder::$created['STF-FIN']);
        $this->actingAs($this->hr())->get("/manpower/mpp/{$plan->id}/export")->assertOk()->assertDownload('mpp-head-office-2026-q4.xlsx');

        // HR fills Plan 5 and a promotion in October in the exported sheet.
        $book = MppExcel::build($plan->fresh('workLocation'));
        $ws = $book->getActiveSheet();
        for ($r = 6; $r <= $ws->getHighestDataRow(); $r++) {
            if ($ws->getCell("B{$r}")->getValue() === $stf->name) {
                $ws->setCellValue("C{$r}", 5);
                $ws->setCellValue("E{$r}", 1);
                $ws->setCellValue([12 + 9, $r], 1); // month 10 of "Waktu Promosi…"
            }
        }
        $ws->setCellValue('B'.($ws->getHighestDataRow() + 1), 'JABATAN YANG TIDAK ADA');
        $path = tempnam(sys_get_temp_dir(), 'mpp').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'mpp.xlsx', null, null, true);

        $preview = $this->post("/manpower/mpp/{$plan->id}/import", ['file' => $file])->assertOk()->json();
        $this->assertSame([['field' => 'plan', 'before' => 0, 'after' => 5], ['field' => 'promosi', 'before' => 0, 'after' => 1], ['field' => 'bulan_pindah', 'before' => '—', 'after' => 10]],
            collect($preview['perubahan'])->firstWhere('jabatan_id', $stf->id)['fields']);
        $this->post("/manpower/mpp/{$plan->id}/import", ['file' => $file, 'simpan' => 1])->assertSessionHasNoErrors();
        $this->assertSame(5, $plan->items()->where('jabatan_id', $stf->id)->value('plan'));
    }

    public function test_master_data_switches_to_semesters(): void
    {
        $this->actingAs($this->hr())->put('/manpower/master-data/mpp', ['periode' => 'semester', 'golongan_sub_group' => []])->assertSessionHasNoErrors();
        MppAturan::forget();
        $this->get('/manpower/mpp')->assertInertia(fn (Assert $page) => $page->where('periode.kode', '2026-S2'));
        $this->assertTrue(MppPlan::where(['jenis' => 'semester', 'ke' => 2])->exists());
    }
}
