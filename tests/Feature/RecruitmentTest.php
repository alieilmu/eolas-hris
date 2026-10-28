<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Candidate;
use App\Models\Employee;
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
        return User::where('email', 'hr@eolas.local')->firstOrFail();
    }

    private function operatorForm(): JobPosting
    {
        return JobPosting::with('fields')->where('title', 'Operator Alat Berat')->firstOrFail();
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

        return $this->post("/careers/{$form->id}", ['answers' => array_replace($answers, $override), 'agree' => '1']);
    }

    public function test_the_public_pages_show_open_vacancies_and_take_applications(): void
    {
        $this->get('/careers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Careers/Index')
            ->has('forms', 2)
            ->where('forms.0.title', fn ($t) => in_array($t, ['Operator Alat Berat', 'Mekanik Alat Berat'], true)));
        $closed = JobPosting::where('title', 'Staff HRGA')->firstOrFail();
        $this->get("/careers/{$closed->id}")->assertInertia(fn (Assert $page) => $page->where('form.accepting', false)->has('form.fields', 0));
        $this->post("/careers/{$closed->id}", ['agree' => '1'])->assertSessionHasErrors('form');

        // Missing name, a wrong phone and a .exe CV.
        $form = $this->operatorForm();
        $name = $form->fields->firstWhere('field_key', 'name')->id;
        $hp = $form->fields->firstWhere('field_key', 'phone')->id;
        $cv = $form->fields->firstWhere('field_key', 'cv')->id;
        $this->apply([$name => '', $hp => 'abc', $cv => UploadedFile::fake()->create('cv.exe', 10)])
            ->assertSessionHasErrors(["answers.{$name}", "answers.{$hp}", "answers.{$cv}"]);
        $this->assertSame(0, Applicant::count());

        $this->apply()->assertSessionHasNoErrors();
        $a = Applicant::firstOrFail();
        $this->assertSame('new', $a->status);
        $this->assertSame('Andi Saputra', $a->name());
        $this->assertSame('0812 3456 7890', $a->answers('phone'));
        Storage::disk('local')->assertExists($a->answers('cv')['path']);

        // The admins of Recruitment hear of it; one who may only look does not.
        $viewer = User::create(['name' => 'Lihat', 'email' => 'view@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['recruitment' => Menu::VIEW], 'is_active' => true]);
        $this->apply()->assertSessionHasNoErrors();
        $this->assertSame('Pelamar Baru', $this->admin()->unreadNotifications()->firstOrFail()->data['title']);
        $this->assertSame(2, $this->hr()->unreadNotifications()->count());
        $this->assertSame(0, $viewer->notifications()->count());

        // A bot filling the hidden field: nothing saved.
        $this->post("/careers/{$form->id}", ['website' => 'http://spam.example', 'agree' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Applicant::count());
    }

    public function test_an_applicant_moves_to_tracking_with_their_answers(): void
    {
        $this->apply();
        $a = Applicant::firstOrFail();
        $this->actingAs($this->admin());

        $this->get('/recruitment/applicants')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Applicants')
            ->where('counts.new', 1)
            ->where('applicants.data.0.name', 'Andi Saputra')
            ->where('applicants.data.0.position_title', 'Operator Dump Truck / Excavator'));
        $this->getJson("/recruitment/applicants/{$a->id}")->assertJsonPath('answers.0.value', 'Andi Saputra')->assertJsonPath('cv.name', 'cv-andi.pdf');
        $cvField = collect($a->data)->search(fn ($x) => $x['field_key'] === 'cv');
        $this->get("/recruitment/applicants/{$a->id}/file/{$cvField}")->assertOk();

        $this->post("/recruitment/applicants/{$a->id}/tracking", ['position' => 'Operator Excavator'])->assertSessionHasNoErrors();
        $c = Candidate::firstOrFail();
        $first = RecruitmentStage::orderBy('sort_order')->firstOrFail();
        $this->assertSame([$first->id, 'Andi Saputra', 'andi.saputra@example.com', '0812 3456 7890', 'Operator Excavator'], [$c->recruitment_stage_id, $c->name, $c->email, $c->phone, $c->position]);
        $this->assertNotNull($c->cv_path);
        $this->assertSame('in_process', $a->fresh()->status);
        // Handled: the bell is quiet again.
        $this->assertSame(0, DatabaseNotification::whereNull('read_at')->count());
        $this->post("/recruitment/applicants/{$a->id}/tracking")->assertSessionHasErrors('applicant');

        // Deleting the card sends the application back to Pelamar.
        $this->delete("/recruitment/candidate/{$c->id}")->assertSessionHasNoErrors();
        $this->assertSame('new', $a->fresh()->status);
        $this->post("/recruitment/applicants/{$a->id}/reject", ['reject' => true, 'notes' => 'Belum ada SIMPER'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $a->fresh()->status);
        $path = $a->answers('cv')['path'];
        $this->delete("/recruitment/applicants/{$a->id}")->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_candidates_move_between_stages_and_get_hired(): void
    {
        $this->actingAs($this->admin());
        $stages = RecruitmentStage::orderBy('sort_order')->get();
        $this->post('/recruitment/candidate', [
            'stage_id' => $stages[0]->id, 'name' => 'Budi Hartono', 'email' => 'budi@example.com', 'phone' => '081299998888',
            'position' => 'Operator Dump Truck', 'applied_date' => '2026-10-01', 'cv' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $c = Candidate::firstOrFail();

        $this->get('/recruitment/pipeline')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Pipeline')
            ->has('stages', $stages->count())
            ->where('stages.0.candidates.0.name', 'Budi Hartono')
            ->where('stages.0.candidates.0.has_cv', true));

        // Dragged to the next stage: kept in the history.
        $this->post('/recruitment/candidate/move', ['candidate_id' => $c->id, 'stage_id' => $stages[1]->id, 'ordered_ids' => [$c->id]])->assertSessionHasNoErrors();
        $this->assertSame([$stages[1]->id, $stages[1]->title], [$c->fresh()->recruitment_stage_id, $c->fresh()->status]);
        $this->postJson("/recruitment/candidate/{$c->id}/notes", ['content' => 'Psikotes lulus, nilai B'])->assertCreated();
        $show = $this->getJson("/recruitment/candidate/{$c->id}")->assertOk();
        $show->assertJsonPath('history.0.to', $stages[1]->title)->assertJsonPath('notes.0.content', 'Psikotes lulus, nilai B')->assertJsonPath('last_stage', false);
        $this->deleteJson('/recruitment/notes/'.$c->notes()->value('id'))->assertOk();

        // A stage with candidates cannot go; renaming it renames the cards' status.
        $this->delete("/recruitment/stage/{$stages[1]->id}")->assertSessionHasErrors('stage');
        $this->put("/recruitment/stage/{$stages[1]->id}", ['title' => 'Psikotes & Tes Tulis', 'color' => '#f43f5e'])->assertSessionHasNoErrors();
        $this->assertSame('Psikotes & Tes Tulis', $c->fresh()->status);
        $this->post("/recruitment/stage/{$stages[1]->id}/move", ['direction' => 'left'])->assertSessionHasNoErrors();
        $this->assertSame($stages[1]->id, RecruitmentStage::orderBy('sort_order')->value('id'));

        // Not taken further, then back.
        $this->post("/recruitment/candidate/{$c->id}/reject", ['reject' => true])->assertSessionHasErrors('reason');
        $this->post("/recruitment/candidate/{$c->id}/reject", ['reject' => true, 'reason' => 'Tidak lolos MCU'])->assertSessionHasNoErrors();
        $this->get('/recruitment/pipeline')->assertInertia(fn (Assert $page) => $page->where('quantity.rejected', 1)->where('stages', fn ($s) => collect($s)->sum(fn ($x) => count($x['candidates'])) === 0));
        $this->post("/recruitment/candidate/{$c->id}/reject", ['reject' => false])->assertSessionHasNoErrors();

        // Hired at the last stage: an employee linked to the candidate.
        $last = RecruitmentStage::orderByDesc('sort_order')->firstOrFail();
        $this->post('/recruitment/candidate/move', ['candidate_id' => $c->id, 'stage_id' => $last->id, 'ordered_ids' => [$c->id]]);
        $position = OrgStructureSeeder::$created['OPR-PRD'];
        $this->post("/recruitment/candidate/{$c->id}/employee", ['employee_number' => '123', 'name' => 'Budi Hartono', 'position_id' => $position])->assertSessionHasErrors('employee_number');
        $this->post("/recruitment/candidate/{$c->id}/employee", ['employee_number' => '11526150', 'name' => 'Budi Hartono', 'hire_date' => '2026-10-15', 'position_id' => $position])->assertSessionHasNoErrors();
        $k = Employee::with('profile')->where('employee_number', '11526150')->firstOrFail();
        $this->assertSame([$c->id, $position, '2026-10-15', '081299998888'], [$k->candidate_id, $k->position_id, $k->profile->hire_date->toDateString(), $k->profile->phone]);
        $this->getJson("/recruitment/candidate/{$c->id}")->assertJsonPath('employee.employee_number', '11526150');
        $this->post("/recruitment/candidate/{$c->id}/employee", ['employee_number' => '11526151', 'name' => 'Budi', 'position_id' => $position])->assertSessionHasErrors('employee_number');
        $this->delete("/recruitment/candidate/{$c->id}")->assertSessionHasErrors('candidate');
    }

    public function test_a_hired_applicant_keeps_the_birth_date_from_the_form(): void
    {
        $this->apply();
        $a = Applicant::firstOrFail();
        $this->actingAs($this->admin());
        $this->post("/recruitment/applicants/{$a->id}/tracking", ['position' => 'Operator Excavator'])->assertSessionHasNoErrors();
        $c = Candidate::firstOrFail();
        $this->post("/recruitment/candidate/{$c->id}/employee", ['employee_number' => '11526160', 'name' => 'Andi Saputra', 'position_id' => OrgStructureSeeder::$created['OPR-PRD']])
            ->assertSessionHasNoErrors();
        $this->assertSame('1998-05-17', Employee::with('profile')->where('employee_number', '11526160')->firstOrFail()->profile->birth_date->toDateString());
    }

    public function test_forms_are_built_question_by_question(): void
    {
        $this->actingAs($this->admin());
        $this->post('/recruitment/job-postings')->assertRedirect();
        $form = JobPosting::latest('id')->firstOrFail();
        $this->get("/recruitment/job-postings/{$form->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Recruitment/JobPostingEditor')->has('form.fields', 7));

        $fields = $form->fields->map(fn ($f) => $f->only(['id', 'label', 'type', 'field_key', 'required', 'options', 'placeholder']))->all();
        $keep = $fields[0]['id'];
        // No "nama", and the CV as a text answer: refused, with every reason.
        $bad = $fields;
        $bad[0]['field_key'] = null;
        $bad[6]['type'] = 'text';
        $this->put("/recruitment/job-postings/{$form->id}", ['title' => 'Welder', 'fields' => $bad])->assertSessionHasErrors('fields');

        $fields[] = ['id' => null, 'label' => 'Sertifikat Las', 'type' => 'select', 'field_key' => null, 'required' => true, 'options' => ['SMAW', 'GTAW', ' ', 'SMAW'], 'placeholder' => null];
        unset($fields[5]);
        $this->put("/recruitment/job-postings/{$form->id}", ['title' => 'Welder', 'position_title' => 'Welder', 'location' => 'Site Rantau', 'closing_date' => '2026-12-31', 'is_open' => true, 'fields' => array_values($fields)])
            ->assertSessionHasNoErrors();
        $form->refresh()->load('fields');
        $this->assertSame(['Welder', true, 7], [$form->title, $form->is_open, $form->fields->count()]);
        $this->assertTrue($form->fields->contains('id', $keep));
        $this->assertSame(['SMAW', 'GTAW'], $form->fields->firstWhere('label', 'Sertifikat Las')->options);

        $this->post("/recruitment/job-postings/{$form->id}/toggle")->assertSessionHasNoErrors();
        $this->assertFalse($form->fresh()->is_open);
        $this->post("/recruitment/job-postings/{$form->id}/duplicate")->assertRedirect();
        $this->assertSame(7, JobPosting::latest('id')->firstOrFail()->fields()->count());

        $this->apply();
        $this->delete('/recruitment/job-postings/'.$this->operatorForm()->id)->assertSessionHasErrors('form');
        $this->delete("/recruitment/job-postings/{$form->id}")->assertSessionHasNoErrors();
    }

    public function test_the_report_and_its_export(): void
    {
        $this->apply();
        $this->actingAs($this->admin());
        $this->post('/recruitment/applicants/'.Applicant::firstOrFail()->id.'/tracking')->assertSessionHasNoErrors();
        $year = now()->year;

        $this->get('/recruitment/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Recruitment/Reports')
            ->where('summary.applicants', 1)
            ->where('summary.candidate', 1)
            ->where('stages.0.reached', 1)
            ->where('stages.1.reached', 0)
            ->where('per_form.0.in_process', 1));
        $this->get("/recruitment/reports/export?year={$year}")->assertOk()->assertDownload("report-recruitment-{$year}-".now()->format('Y-m-d').'.xlsx');
    }

    public function test_looking_is_not_changing(): void
    {
        $viewer = User::create(['name' => 'Lihat', 'email' => 'view@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['recruitment' => Menu::VIEW], 'is_active' => true]);
        $this->actingAs($viewer)->get('/recruitment/pipeline')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
        $this->post('/recruitment/stage', ['title' => 'X', 'color' => '#000000'])->assertForbidden();
        $this->post('/recruitment/job-postings')->assertForbidden();
        $other = User::create(['name' => 'Lain', 'email' => 'lain@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['employees' => Menu::EDIT], 'is_active' => true]);
        $this->actingAs($other)->get('/recruitment/applicants')->assertForbidden();
    }
}
