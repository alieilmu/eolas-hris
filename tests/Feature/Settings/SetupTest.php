<?php

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\Directorate;
use App\Models\Division;
use App\Models\JobLevel;
use App\Models\Section;
use App\Models\User;
use App\Models\WorkspaceSetup;
use Database\Seeders\EolasBlankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Sesi awal of a workspace for a company's own data. */
class SetupTest extends TestCase
{
    use RefreshDatabase;

    private function blankWorkspace(): User
    {
        $this->seed(EolasBlankSeeder::class);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'must_change_password' => false]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_super_admin_of_a_blank_workspace_starts_with_the_setup(): void
    {
        $this->blankWorkspace();

        $this->get('/dashboard')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Setup/Wizard')
            ->where('step', 'company')->where('setup.status', 'pending')->where('employee_number_format.mode', 'pattern'));

        // Skipping a step moves on to the next one.
        $this->post('/setup/steps/company', ['state' => 'skipped'])->assertRedirect('/setup?step=employee_number');
        $this->post('/setup/steps/employee_number', ['state' => 'done'])->assertRedirect('/setup?step=organization');
        $this->get('/setup')->assertInertia(fn (Assert $page) => $page->where('step', 'organization'));

        $this->post('/setup/finish')->assertRedirect('/dashboard');
        $this->assertSame('completed', WorkspaceSetup::current()->status);
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_organisation_outline_builds_the_units_once(): void
    {
        $this->blankWorkspace();
        $outline = "Direktur Utama\n  Operasional\n    Produksi\n      Cutting\n      Sewing\n    Quality Control\n  Umum & Keuangan\n    HRGA";

        $this->post('/setup/organization', ['outline' => $outline, 'standard_job_levels' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, Directorate::count());
        $this->assertSame(['Operasional', 'Umum & Keuangan'], Division::orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(['Produksi', 'Quality Control', 'HRGA'], Department::orderBy('id')->pluck('name')->all());
        $this->assertSame(['Cutting', 'Sewing'], Section::orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(6, JobLevel::count());

        // Pasted again with one more section: nothing doubled.
        $this->post('/setup/organization', ['outline' => $outline."\n      Gudang"])->assertSessionHasNoErrors();
        $this->assertSame(1, Directorate::count());
        $this->assertSame(['HRGA'], Department::whereHas('sections', fn ($q) => $q->where('name', 'Gudang'))->pluck('name')->all());

        // Pasted with every line indented: read relative to the first line.
        $this->post('/setup/organization', ['outline' => "    Direktur Keuangan\n      Treasury"])->assertSessionHasNoErrors();
        $this->assertSame(['Direktur Utama', 'Direktur Keuangan'], Directorate::orderBy('sort_order')->pluck('name')->all());
        $this->post('/setup/organization', ['outline' => "A\n  B\n    C\n      D\n        E"])->assertSessionHasErrors('outline');
    }

    public function test_skip_all_and_a_workspace_without_setup(): void
    {
        $this->blankWorkspace();
        $this->post('/setup/dismiss')->assertRedirect('/dashboard');
        $this->assertSame('dismissed', WorkspaceSetup::current()->status);
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setup', 'dismissed'));
        $this->get('/setup')->assertOk(); // still there from Administrasi

        WorkspaceSetup::query()->delete();
        $this->get('/setup')->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setup', null));
    }
}
