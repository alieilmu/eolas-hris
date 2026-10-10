<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\Candidate;
use App\Models\Karyawan;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Support\Access\Menu;
use Database\Seeders\OrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecruitmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@eloas.local')->firstOrFail();
    }

    private function operatorForm(): ApplicationForm
    {
        return ApplicationForm::with('fields')->where('title', 'Operator Alat Berat')->firstOrFail();
    }

    /** A complete application to the operator form. */
    private function apply(array $override = []): TestResponse
    {
        $form = $this->operatorForm();
        $answers = [];
        foreach ($form->fields as $f) {
            $answers[$f->id] = match ($f->label) {
                'Nama Lengkap' => 'Andi Saputra',
                'Email' => 'andi.saputra@example.com',
                'Nomor HP / WhatsApp' => '0812 3456 7890',
                'Tanggal Lahir' => '1998-05-17',
                'Pendidikan Terakhir' => 'SMA/SMK',
                'Unit yang Dikuasai' => 'Excavator (PC2000 / PC1250)',
                'Pengalaman Mengoperasikan (tahun)' => '4',
                'CV' => UploadedFile::fake()->create('cv-andi.pdf', 120, 'application/pdf'),
                default => null,
            };
        }

        return $this->post("/lowongan/{$form->id}", ['jawaban' => array_replace($answers, $override), 'setuju' => '1']);
    }

    public function test_the_public_pages_show_open_vacancies_and_take_applications(): void
    {
        $this->get('/lowongan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lowongan/Index')
            ->has('forms', 2)
            ->where('forms.0.title', fn ($t) => in_array($t, ['Operator Alat Berat', 'Mekanik Alat Berat'], true)));
        $closed = ApplicationForm::where('title', 'Staff HRGA')->firstOrFail();
        $this->get("/lowongan/{$closed->id}")->assertInertia(fn (Assert $page) => $page->where('form.accepting', false)->has('form.fields', 0));
        $this->post("/lowongan/{$closed->id}", ['setuju' => '1'])->assertSessionHasErrors('form');

        // Missing name, a wrong phone and a .exe CV.
        $form = $this->operatorForm();
        $nama = $form->fields->firstWhere('kunci', 'nama')->id;
        $hp = $form->fields->firstWhere('kunci', 'no_hp')->id;
        $cv = $form->fields->firstWhere('kunci', 'cv')->id;
        $this->apply([$nama => '', $hp => 'abc', $cv => UploadedFile::fake()->create('cv.exe', 10)])
            ->assertSessionHasErrors(["jawaban.{$nama}", "jawaban.{$hp}", "jawaban.{$cv}"]);
        $this->assertSame(0, Applicant::count());

        $this->apply()->assertSessionHasNoErrors();
        $a = Applicant::firstOrFail();
        $this->assertSame('baru', $a->status);
        $this->assertSame('Andi Saputra', $a->nama());
        $this->assertSame('0812 3456 7890', $a->jawaban('no_hp'));
        Storage::disk('local')->assertExists($a->jawaban('cv')['path']);

        // The admins of Recruitment hear of it; one who may only look does not.
        $viewer = User::create(['name' => 'Lihat', 'email' => 'lihat@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['recruitment' => Menu::VIEW], 'is_active' => true]);
        $this->apply()->assertSessionHasNoErrors();
        $this->assertSame('Pelamar Baru', $this->admin()->unreadNotifications()->firstOrFail()->data['judul']);
        $this->assertSame(2, $this->hr()->unreadNotifications()->count());
        $this->assertSame(0, $viewer->notifications()->count());

        // A bot filling the hidden field: nothing saved.
        $this->post("/lowongan/{$form->id}", ['website' => 'http://spam.example', 'setuju' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Applicant::count());
    }

    public function test_an_applicant_moves_to_tracking_with_their_answers(): void
    {
        $this->apply();
        $a = Applicant::firstOrFail();
        $this->actingAs($this->admin());

        $this->get('/recruitment/pelamar')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Pelamar')
            ->where('counts.baru', 1)
            ->where('applicants.data.0.nama', 'Andi Saputra')
            ->where('applicants.data.0.posisi', 'Operator Dump Truck / Excavator'));
        $this->getJson("/recruitment/pelamar/{$a->id}")->assertJsonPath('jawaban.0.value', 'Andi Saputra')->assertJsonPath('cv.nama', 'cv-andi.pdf');
        $cvField = collect($a->data)->search(fn ($x) => $x['kunci'] === 'cv');
        $this->get("/recruitment/pelamar/{$a->id}/file/{$cvField}")->assertOk();

        $this->post("/recruitment/pelamar/{$a->id}/tracking", ['position' => 'Operator Excavator'])->assertSessionHasNoErrors();
        $c = Candidate::firstOrFail();
        $first = RecruitmentStage::orderBy('order')->firstOrFail();
        $this->assertSame([$first->id, 'Andi Saputra', 'andi.saputra@example.com', '0812 3456 7890', 'Operator Excavator'], [$c->recruitment_stage_id, $c->name, $c->email, $c->no_hp, $c->position]);
        $this->assertNotNull($c->cv_path);
        $this->assertSame('diproses', $a->fresh()->status);
        // Handled: the bell is quiet again.
        $this->assertSame(0, DatabaseNotification::whereNull('read_at')->count());
        $this->post("/recruitment/pelamar/{$a->id}/tracking")->assertSessionHasErrors('applicant');

        // Deleting the card sends the application back to Pelamar.
        $this->delete("/recruitment/kandidat/{$c->id}")->assertSessionHasNoErrors();
        $this->assertSame('baru', $a->fresh()->status);
        $this->post("/recruitment/pelamar/{$a->id}/tolak", ['tolak' => true, 'catatan' => 'Belum ada SIMPER'])->assertSessionHasNoErrors();
        $this->assertSame('ditolak', $a->fresh()->status);
        $path = $a->jawaban('cv')['path'];
        $this->delete("/recruitment/pelamar/{$a->id}")->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_candidates_move_between_stages_and_get_hired(): void
    {
        $this->actingAs($this->admin());
        $stages = RecruitmentStage::orderBy('order')->get();
        $this->post('/recruitment/kandidat', [
            'stage_id' => $stages[0]->id, 'name' => 'Budi Hartono', 'email' => 'budi@example.com', 'no_hp' => '081299998888',
            'position' => 'Operator Dump Truck', 'applied_date' => '2026-10-01', 'cv' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $c = Candidate::firstOrFail();

        $this->get('/recruitment/tracking')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Tracking')
            ->has('stages', $stages->count())
            ->where('stages.0.candidates.0.name', 'Budi Hartono')
            ->where('stages.0.candidates.0.has_cv', true));

        // Dragged to the next stage: kept in the history.
        $this->post('/recruitment/kandidat/pindah', ['candidate_id' => $c->id, 'stage_id' => $stages[1]->id, 'ordered_ids' => [$c->id]])->assertSessionHasNoErrors();
        $this->assertSame([$stages[1]->id, $stages[1]->title], [$c->fresh()->recruitment_stage_id, $c->fresh()->status]);
        $this->postJson("/recruitment/kandidat/{$c->id}/catatan", ['content' => 'Psikotes lulus, nilai B'])->assertCreated();
        $show = $this->getJson("/recruitment/kandidat/{$c->id}")->assertOk();
        $show->assertJsonPath('history.0.to', $stages[1]->title)->assertJsonPath('notes.0.content', 'Psikotes lulus, nilai B')->assertJsonPath('tahap_terakhir', false);
        $this->deleteJson('/recruitment/catatan/'.$c->notes()->value('id'))->assertOk();

        // A stage with candidates cannot go; renaming it renames the cards' status.
        $this->delete("/recruitment/tahap/{$stages[1]->id}")->assertSessionHasErrors('stage');
        $this->put("/recruitment/tahap/{$stages[1]->id}", ['title' => 'Psikotes & Tes Tulis', 'color' => '#f43f5e'])->assertSessionHasNoErrors();
        $this->assertSame('Psikotes & Tes Tulis', $c->fresh()->status);
        $this->post("/recruitment/tahap/{$stages[1]->id}/geser", ['arah' => 'kiri'])->assertSessionHasNoErrors();
        $this->assertSame($stages[1]->id, RecruitmentStage::orderBy('order')->value('id'));

        // Not taken further, then back.
        $this->post("/recruitment/kandidat/{$c->id}/tolak", ['tolak' => true])->assertSessionHasErrors('alasan');
        $this->post("/recruitment/kandidat/{$c->id}/tolak", ['tolak' => true, 'alasan' => 'Tidak lolos MCU'])->assertSessionHasNoErrors();
        $this->get('/recruitment/tracking')->assertInertia(fn (Assert $page) => $page->where('jumlah.ditolak', 1)->where('stages', fn ($s) => collect($s)->sum(fn ($x) => count($x['candidates'])) === 0));
        $this->post("/recruitment/kandidat/{$c->id}/tolak", ['tolak' => false])->assertSessionHasNoErrors();

        // Hired at the last stage: an employee linked to the candidate.
        $last = RecruitmentStage::orderByDesc('order')->firstOrFail();
        $this->post('/recruitment/kandidat/pindah', ['candidate_id' => $c->id, 'stage_id' => $last->id, 'ordered_ids' => [$c->id]]);
        $jabatan = OrgStructureSeeder::$created['OPR-PRD'];
        $this->post("/recruitment/kandidat/{$c->id}/karyawan", ['nrp' => '123', 'name' => 'Budi Hartono', 'jabatan_id' => $jabatan])->assertSessionHasErrors('nrp');
        $this->post("/recruitment/kandidat/{$c->id}/karyawan", ['nrp' => '11526150', 'name' => 'Budi Hartono', 'tanggal_masuk' => '2026-10-15', 'jabatan_id' => $jabatan])->assertSessionHasNoErrors();
        $k = Karyawan::with('profile')->where('nrp', '11526150')->firstOrFail();
        $this->assertSame([$c->id, $jabatan, '2026-10-15', '081299998888'], [$k->candidate_id, $k->jabatan_id, $k->profile->tanggal_masuk->toDateString(), $k->profile->no_hp]);
        $this->getJson("/recruitment/kandidat/{$c->id}")->assertJsonPath('karyawan.nrp', '11526150');
        $this->post("/recruitment/kandidat/{$c->id}/karyawan", ['nrp' => '11526151', 'name' => 'Budi', 'jabatan_id' => $jabatan])->assertSessionHasErrors('nrp');
        $this->delete("/recruitment/kandidat/{$c->id}")->assertSessionHasErrors('candidate');
    }

    public function test_forms_are_built_question_by_question(): void
    {
        $this->actingAs($this->admin());
        $this->post('/recruitment/form')->assertRedirect();
        $form = ApplicationForm::latest('id')->firstOrFail();
        $this->get("/recruitment/form/{$form->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Recruitment/FormEditor')->has('form.fields', 7));

        $fields = $form->fields->map(fn ($f) => $f->only(['id', 'label', 'type', 'kunci', 'required', 'options', 'placeholder']))->all();
        $keep = $fields[0]['id'];
        // No "nama", and the CV as a text answer: refused, with every reason.
        $bad = $fields;
        $bad[0]['kunci'] = null;
        $bad[6]['type'] = 'text';
        $this->put("/recruitment/form/{$form->id}", ['title' => 'Welder', 'fields' => $bad])->assertSessionHasErrors('fields');

        $fields[] = ['id' => null, 'label' => 'Sertifikat Las', 'type' => 'select', 'kunci' => null, 'required' => true, 'options' => ['SMAW', 'GTAW', ' ', 'SMAW'], 'placeholder' => null];
        unset($fields[5]);
        $this->put("/recruitment/form/{$form->id}", ['title' => 'Welder', 'posisi' => 'Welder', 'lokasi' => 'Site Rantau', 'batas_lamaran' => '2026-12-31', 'is_open' => true, 'fields' => array_values($fields)])
            ->assertSessionHasNoErrors();
        $form->refresh()->load('fields');
        $this->assertSame(['Welder', true, 7], [$form->title, $form->is_open, $form->fields->count()]);
        $this->assertTrue($form->fields->contains('id', $keep));
        $this->assertSame(['SMAW', 'GTAW'], $form->fields->firstWhere('label', 'Sertifikat Las')->options);

        $this->post("/recruitment/form/{$form->id}/buka-tutup")->assertSessionHasNoErrors();
        $this->assertFalse($form->fresh()->is_open);
        $this->post("/recruitment/form/{$form->id}/salin")->assertRedirect();
        $this->assertSame(7, ApplicationForm::latest('id')->firstOrFail()->fields()->count());

        $this->apply();
        $this->delete('/recruitment/form/'.$this->operatorForm()->id)->assertSessionHasErrors('form');
        $this->delete("/recruitment/form/{$form->id}")->assertSessionHasNoErrors();
    }

    public function test_the_report_and_its_export(): void
    {
        $this->apply();
        $this->actingAs($this->admin());
        $this->post('/recruitment/pelamar/'.Applicant::firstOrFail()->id.'/tracking')->assertSessionHasNoErrors();
        $year = now()->year;

        $this->get('/recruitment/laporan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Laporan')
            ->where('ringkasan.pelamar', 1)
            ->where('ringkasan.kandidat', 1)
            ->where('tahapan.0.mencapai', 1)
            ->where('tahapan.1.mencapai', 0)
            ->where('per_form.0.diproses', 1));
        $this->get("/recruitment/laporan/export?tahun={$year}")->assertOk()->assertDownload("laporan-recruitment-{$year}-".now()->format('Y-m-d').'.xlsx');
    }

    public function test_looking_is_not_changing(): void
    {
        $viewer = User::create(['name' => 'Lihat', 'email' => 'lihat@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['recruitment' => Menu::VIEW], 'is_active' => true]);
        $this->actingAs($viewer)->get('/recruitment/tracking')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
        $this->post('/recruitment/tahap', ['title' => 'X', 'color' => '#000000'])->assertForbidden();
        $this->post('/recruitment/form')->assertForbidden();
        $other = User::create(['name' => 'Lain', 'email' => 'lain@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['karyawan' => Menu::EDIT], 'is_active' => true]);
        $this->actingAs($other)->get('/recruitment/pelamar')->assertForbidden();
    }
}
