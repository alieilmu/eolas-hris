<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreHr\Settings\EmployeeNumberFormatController;
use App\Models\Branding;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\JobLevel;
use App\Models\PointOfHire;
use App\Models\Position;
use App\Models\WorkLocation;
use App\Models\WorkspaceSetup;
use App\Support\Employees\ColumnGroups;
use App\Support\Setup\OrganizationOutline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sesi awal: the first-run setup of a workspace for a company's own data, for its Super
 * Admin. Profil perusahaan → Nomor Induk → Organisasi → Migrasi data; every step and the
 * whole setup can be skipped, and it can be opened again from Administrasi.
 */
class SetupController extends Controller
{
    public function show(Request $request): Response
    {
        $setup = $this->setup();
        $step = $request->query('step');
        if (! array_key_exists((string) $step, WorkspaceSetup::STEPS) && $step !== 'done') {
            $step = collect(WorkspaceSetup::STEPS)->keys()->first(fn (string $s) => ! isset($setup->steps[$s])) ?? 'done';
        }
        $branding = Branding::current();
        $employeeUse = Employee::withoutGlobalScope('access')->pluck('employee_number');

        return Inertia::render('Setup/Wizard', [
            'setup' => ['status' => $setup->status, 'steps' => (object) ($setup->steps ?? [])],
            'step_titles' => WorkspaceSetup::STEPS,
            'step' => $step,
            'company' => [
                'company_name' => $branding->company_name, 'tagline' => $branding->tagline,
                'brand_color' => $branding->brand_color, 'accent_color' => $branding->accent_color,
                'logo_url' => $branding->toShare()['logo_url'],
            ],
            'employee_number_format' => EmployeeNumberFormatController::props(),
            'type' => EmployeeCategory::orderBy('code')->get()->map(fn (EmployeeCategory $j) => ['id' => $j->id, 'code' => $j->code, 'name' => $j->name, 'employee_count' => $j->employeeCount()]),
            'location' => PointOfHire::orderBy('code')->get()->map(fn (PointOfHire $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'work_location_id' => $l->work_location_id]),
            'work_locations' => WorkLocation::withCount(['employees', 'pointsOfHire'])->orderBy('sort_order')->get()->map(fn (WorkLocation $w) => [
                'id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'type' => $w->type,
                'employee_count' => $w->employees_count, 'location_count' => $w->points_of_hire_count,
                'position_count' => Position::where('work_location_id', $w->id)->count(),
            ]),
            'outline' => OrganizationOutline::current(),
            'job_levels' => JobLevel::orderByDesc('layer')->orderBy('name')->get(['id', 'name', 'layer', 'source']),
            'standard_job_levels' => collect(OrganizationOutline::STANDARD_JOB_LEVELS)->map(fn ($v, $name) => ['name' => $name, 'layer' => $v[0]])->values(),
            'counts' => ['employees' => $employeeUse->count(), 'positions' => Position::count(), 'work_locations' => WorkLocation::count()],
            'groups' => ColumnGroups::build(),
        ]);
    }

    /** A step done or skipped; then on to the next one. */
    public function step(Request $request, string $step): RedirectResponse
    {
        abort_unless(array_key_exists($step, WorkspaceSetup::STEPS), 404);
        $data = $request->validate(['state' => ['required', Rule::in(['done', 'skipped'])]]);
        $setup = $this->setup();
        $setup->mark($step, $data['state']);
        $next = collect(WorkspaceSetup::STEPS)->keys()->skipUntil($step)->skip(1)->first() ?? 'done';

        return redirect()->route('setup', ['step' => $next]);
    }

    public function organization(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'outline' => ['nullable', 'string', 'max:20000'],
            'standard_job_levels' => ['boolean'],
        ]);
        $created = DB::transaction(function () use ($data) {
            $units = OrganizationOutline::apply($data['outline'] ?? '');
            $levels = ($data['standard_job_levels'] ?? false) ? OrganizationOutline::seedStandardJobLevels() : 0;

            return [...$units, 'job_levels' => $levels];
        });
        $parts = array_filter([
            $created['directorate'] ? "{$created['directorate']} direktorat" : null,
            $created['division'] ? "{$created['division']} divisi" : null,
            $created['department'] ? "{$created['department']} departemen" : null,
            $created['section'] ? "{$created['section']} section" : null,
            $created['job_levels'] ? "{$created['job_levels']} SubGroup" : null,
        ]);
        Inertia::flash('success', $parts ? 'Ditambahkan: '.implode(', ', $parts).'.' : 'Tidak ada unit baru; struktur sudah sama.');

        return back();
    }

    public function finish(): RedirectResponse
    {
        $this->setup()->update(['status' => 'completed', 'completed_at' => now()]);
        Inertia::flash('success', 'Sesi awal selesai. Selamat menggunakan Eolas HRIS!');

        return redirect()->route('dashboard');
    }

    /** "Lewati semua": no more taking the Super Admin to the setup; it stays in Administrasi. */
    public function dismiss(): RedirectResponse
    {
        $this->setup()->update(['status' => 'dismissed']);
        Inertia::flash('success', 'Sesi awal dilewati. Buka lagi kapan saja dari Administrasi → Sesi Awal.');

        return redirect()->route('dashboard');
    }

    private function setup(): WorkspaceSetup
    {
        return WorkspaceSetup::current() ?? abort(404);
    }
}
