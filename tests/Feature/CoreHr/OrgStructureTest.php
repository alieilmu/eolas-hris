<?php

namespace Tests\Feature\CoreHr;

use App\Models\Directorate;
use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Models\Employee;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\SubLocation;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OrgStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function position(string $key): Position
    {
        return $this->seededPosition($key);
    }

    private function named(string $name): Employee
    {
        return Employee::where('name', $name)->firstOrFail();
    }

    private function assertErrorContains(string $key, string $needle): void
    {
        $this->assertStringContainsString($needle, session('errors')->first($key));
    }

    /** A placement: SubGroup + work location + unit (by level and name) + bidang. */
    private function placement(string $jobLevel, string $location, ?string $level = null, ?string $unit = null, ?string $discipline = null): array
    {
        $models = ['directorate' => Directorate::class, 'division' => Division::class, 'department' => Department::class, 'section' => Section::class];

        return array_filter([
            'job_level_id' => JobLevel::where('name', $jobLevel)->value('id'),
            'work_location_id' => WorkLocation::where('name', $location)->value('id'),
            $level ? "{$level}_id" : null => $level ? $models[$level]::where('name', $unit)->value('id') : null,
            'discipline' => $discipline,
        ], fn ($v, $k) => $k !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function location(string $name): WorkLocation
    {
        return WorkLocation::where('name', $name)->firstOrFail();
    }

    /** Changes made in the plan of a work location. */
    private function ops(string $location, array $ops): TestResponse
    {
        return $this->post('/core-hr/org-chart/plan/'.$this->location($location)->id.'/ops', ['ops' => $ops]);
    }

    private function createOp(string $temp, array $placement, int|string|null $parent = null): array
    {
        return ['type' => 'create', 'temp_id' => $temp, 'placement' => $placement, 'parent' => $parent];
    }

    /** The node with that name anywhere in a tree. */
    private function node(iterable $nodes, string $name): ?array
    {
        foreach ($nodes as $n) {
            $n = (array) (is_object($n) && method_exists($n, 'toArray') ? $n->toArray() : $n);
            if (($n['name'] ?? $n['work_location']['name'] ?? null) === $name) {
                return $n;
            }
            if ($found = $this->node($n['children'], $name)) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<string> the names of a tree's nodes, depth first */
    private function names(array $nodes): array
    {
        return collect($nodes)->flatMap(fn ($n) => [$n['name'] ?? $n['work_location']['name'], ...$this->names($n['children'])])->all();
    }

    public function test_head_office_plan_shows_its_positions_and_the_sites(): void
    {
        // One page for both modes: the plan (Mode Organisasi) and the people (Mode MPP) come together.
        $this->get('/core-hr/org-chart?location='.$this->location('Head Office')->id)->assertInertia(fn (Assert $page) => $page
            ->component('CoreHr/OrgChart/Index')
            ->where('needs_setup', false)
            ->has('people')
            ->where('tree', fn ($tree) => collect($this->names($tree->toArray()))->contains('MANAGER HRGA'))
            // The sites are not set up yet: their cards wait apart from the HO chart.
            ->where('unlinked_sites', fn ($sites) => collect($sites)->pluck('work_location.name')->all() === ['Site Murung Raya', 'Site Rantau']
                && collect($sites)->every(fn ($s) => $s['position'] === null))
            ->has('palette.directorates', 1)
            ->has('palette.layers', 2)
            ->where('palette.unit_job_levels.department', JobLevel::where('name', 'Manager')->value('id'))
            ->where('palette.unit_job_levels.section', JobLevel::where('name', 'Section Head HO')->value('id')));

        // A site starts with its Project Manager.
        $this->get('/core-hr/org-chart?location='.$this->location('Site Rantau')->id)->assertInertia(fn (Assert $page) => $page
            ->where('needs_setup', true)
            ->where('setup.pm_job_level', 'Project Manager')
            ->where('setup.dpm_job_level', 'Deputy Project Manager')
            ->where('tree', []));

        // The old address of the plan opens Mode Organisasi.
        $rantau = $this->location('Site Rantau')->id;
        $this->get("/core-hr/org-chart/plan?location={$rantau}")->assertRedirect("/core-hr/org-chart?location={$rantau}&mode=organization");
    }

    public function test_a_site_is_set_up_from_its_project_manager(): void
    {
        $site = $this->location('Site Murung Raya');
        $irfan = Employee::create([
            'employee_number' => '10115900', 'name' => 'Irfan', 'status' => Employee::STATUS_ACTIVE, 'work_location_id' => $site->id,
            'job_level_id' => JobLevel::where('name', 'Project Manager')->value('id'),
        ]);

        $this->post("/core-hr/org-chart/plan/{$site->id}/setup", [
            'pm_employee_id' => $irfan->id, 'deputy' => true, 'dpm_employee_id' => null, 'superior_position_id' => $this->position('KDP-HRG')->id,
        ])->assertSessionHasNoErrors();

        $pm = Position::where('name', 'PROJECT MANAGER MRY')->firstOrFail();
        $this->assertSame([$pm->id, 'MANAGER HRGA'], [$irfan->fresh()->position_id, $pm->superior()?->name]);
        $this->assertSame($pm->id, Position::where('name', 'DEPUTY PROJECT MANAGER MRY')->value('parent_position_id'), 'a vacant Deputy under the PM');
        // Positions of the site without a superior now report to the PM — except a more senior one.
        $this->assertSame($pm->id, $this->position('KDP-PRD')->parent_position_id);
        $this->assertNull($this->position('KDV-OPS')->parent_position_id, 'a General Manager (layer 5) is not put under a PM (layer 4)');

        // HO shows the site under the PM's superior; the site's chart starts at the PM.
        $this->get('/core-hr/org-chart?location='.$this->location('Head Office')->id)->assertInertia(fn (Assert $page) => $page
            ->where('tree', fn ($tree) => collect($tree)->contains(fn ($n) => ($n['name'] ?? '') === 'MANAGER HRGA'
                && collect($n['children'])->contains(fn ($c) => $c['kind'] === 'site' && $c['position']['holders'][0]['name'] === 'Irfan'))));
        $this->get("/core-hr/org-chart?location={$site->id}")->assertInertia(fn (Assert $page) => $page
            ->where('needs_setup', false)
            ->where('tree.0.name', 'PROJECT MANAGER MRY'));

        $this->post("/core-hr/org-chart/plan/{$site->id}/setup", [])->assertSessionHasErrors('setup');
    }

    public function test_the_plan_adds_positions_and_keeps_the_layer_rule(): void
    {
        $spv = $this->position('SPV-MTC'); // SECTION HEAD WORKSHOP, Site Rantau
        $rantau = $this->location('Site Rantau')->id;
        $before = Position::count();

        // A section dropped under the Dept Head: its Section Head (the site's SubGroup for sections).
        $this->ops('Site Rantau', [
            $this->createOp('temp-a', ['work_location_id' => $rantau, 'job_level_id' => JobLevel::where('name', 'Section Head Site')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id')], $this->position('KDP-MTC')->id),
            // A Layer 2 title under it, in its section.
            $this->createOp('temp-b', ['work_location_id' => $rantau, 'job_level_id' => JobLevel::where('name', 'Officer HO/ Group Leader')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id'), 'discipline' => 'HSE FIELD'], 'temp-a'),
        ])->assertSessionHasNoErrors();
        $gl = Position::where('name', 'GROUP LEADER HSE FIELD')->firstOrFail();
        $this->assertSame([$before + 2, 'SECTION HEAD PERSONALIA'], [Position::count(), $gl->parent?->name]);

        // Once is enough; and never under a lower layer.
        $this->ops('Site Rantau', [$this->createOp('temp-c', ['work_location_id' => $rantau, 'job_level_id' => JobLevel::where('name', 'Section Head Site')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id')])])
            ->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'sudah ada');
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $spv->id, 'parent' => $gl->id]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'layer atasan tidak boleh di bawah bawahannya');
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $gl->parent_position_id, 'parent' => $gl->id]])->assertSessionHasErrors('ops');

        // A Layer 1–2 title joins the unit of its new superior; a Section Head keeps its own.
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $gl->id, 'parent' => $spv->id]])->assertSessionHasNoErrors();
        $this->assertSame('section:'.Section::where('name', 'WORKSHOP')->value('id'), $gl->fresh()->unitKey());
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $spv->id, 'parent' => Position::where('name', 'SECTION HEAD PERSONALIA')->value('id')]])->assertSessionHasNoErrors();
        $this->assertSame(['SECTION HEAD WORKSHOP', 'section:'.Section::where('name', 'WORKSHOP')->value('id')], [$spv->fresh()->name, $spv->fresh()->unitKey()]);

        // Positions with someone on them, or with subordinates, stay.
        $this->ops('Site Rantau', [['type' => 'delete', 'position' => $this->position('MKN-MTC')->id]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'Rudi Hartono');
        $this->ops('Site Rantau', [['type' => 'delete', 'position' => $spv->id]])->assertSessionHasErrors('ops');
        $this->ops('Site Rantau', [['type' => 'delete', 'position' => $gl->id]])->assertSessionHasNoErrors();
        $this->assertModelMissing($gl);

        // A SubGroup's layer cannot change so that a line turns upside down.
        $update = fn (string $nama, int $layer) => $this->put('/core-hr/settings/job-levels/'.JobLevel::where('name', $nama)->value('id'), [
            ...JobLevel::where('name', $nama)->first(['name', 'source', 'title_prefix', 'title_prefix_head_office'])->toArray(),
            'layer' => $layer,
        ]);
        $update('Section Head Site', 4)->assertSessionHasErrors('layer');
        $this->assertErrorContains('layer', 'lebih tinggi dari atasannya DEPT HEAD MAINTENANCE');
    }

    public function test_sub_lokasi_has_its_own_box_and_positions(): void
    {
        $rantau = $this->location('Site Rantau');
        $this->post("/core-hr/org-chart/plan/{$rantau->id}/setup", [])->assertSessionHasNoErrors();
        $this->post('/core-hr/settings/sub-locations', ['name' => 'Jetty', 'work_location_id' => $rantau->id])->assertSessionHasNoErrors();
        $this->post('/core-hr/settings/sub-locations', ['name' => 'jetty', 'work_location_id' => $rantau->id])->assertSessionHasErrors('name');
        $jetty = SubLocation::where('name', 'Jetty')->firstOrFail();
        $dept = $this->position('KDP-MTC');

        // The box hangs under the Dept Head; a MECHANIC there is another position than the one at the site.
        $this->ops('Site Rantau', [
            ['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => $dept->id],
            $this->createOp('temp-a', [
                'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id, 'job_level_id' => JobLevel::where('name', 'Mekanik')->value('id'),
                'section_id' => Section::where('name', 'WORKSHOP')->value('id'), 'discipline' => 'MECHANIC',
            ], $dept->id),
        ])->assertSessionHasNoErrors();
        $there = Position::where('sub_location_id', $jetty->id)->firstOrFail();
        $this->assertSame('MECHANIC', $there->name);
        $this->assertNotSame($this->position('MKN-MTC')->id, $there->id);

        $this->get("/core-hr/org-chart?location={$rantau->id}")->assertInertia(fn (Assert $page) => $page
            ->where('tree.0.name', 'PROJECT MANAGER RTU')
            ->where('tree', fn ($tree) => $this->node($tree, 'Jetty')['children'][0]['name'] === 'MECHANIC'
                && collect($this->node($tree, 'DEPT HEAD MAINTENANCE')['children'])->contains(fn ($c) => $c['kind'] === 'sub_location'))
            ->where('palette.sub_locations.0', ['id' => $jetty->id, 'name' => 'Jetty', 'in_plan' => true]));

        // Someone assigned there fills it; the same placement without the Sub Lokasi is the site's own.
        $this->post('/core-hr/employees', [
            'employee_number' => '10124310', 'name' => 'Ujang', 'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id,
            'job_level_id' => JobLevel::where('name', 'Mekanik')->value('id'), 'section_id' => Section::where('name', 'WORKSHOP')->value('id'), 'discipline' => 'MECHANIC',
        ])->assertSessionHasNoErrors();
        $this->assertSame([$there->id, $jetty->id], [Employee::where('name', 'Ujang')->value('position_id'), Employee::where('name', 'Ujang')->value('sub_location_id')]);

        // Dropped on the box (or "Mutasi"): Rudi, the site's mechanic, moves to the MECHANIC there.
        $rudi = $this->named('Rudi Hartono');
        $this->post("/core-hr/employees/{$rudi->id}/sub-locations", ['sub_location_id' => $jetty->id])->assertSessionHasNoErrors();
        $this->assertSame([$there->id, $jetty->id], [$rudi->fresh()->position_id, $rudi->fresh()->sub_location_id]);

        // Without a matching position there, one is made — under the position the box hangs from.
        $operator = ['job_level_id' => JobLevel::where('name', 'Operator')->value('id'), 'section_id' => Section::where('name', 'OPERASI TAMBANG')->value('id'), 'discipline' => 'PRODUCTION OPERATOR'];
        $this->post('/core-hr/employees', ['employee_number' => '10124311', 'name' => 'Wawan', 'work_location_id' => $rantau->id, ...$operator])->assertSessionHasNoErrors();
        $wawan = $this->named('Wawan');
        $this->assertNull($wawan->position_id, 'not in the site plan: waiting');
        $this->post("/core-hr/employees/{$wawan->id}/sub-locations", ['sub_location_id' => $jetty->id])->assertSessionHasNoErrors();
        $made = $wawan->fresh()->position;
        $this->assertSame(['PRODUCTION OPERATOR', $jetty->id, $dept->id], [$made->name, $made->sub_location_id, $made->parent_position_id]);
        // The same from the form: a placement at the Sub Lokasi fills (or makes) the position there.
        $this->post('/core-hr/employees', ['employee_number' => '10124312', 'name' => 'Yanto', 'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id, ...$operator])
            ->assertSessionHasNoErrors();
        $this->assertSame($made->id, $this->named('Yanto')->position_id);

        // "Mutasi" lists a location's positions and Sub Lokasi.
        $positions = $this->getJson("/core-hr/org-chart/positions?location={$rantau->id}")->assertOk()->assertJsonPath('sub_locations.0.name', 'Jetty');
        $this->assertTrue(collect($positions->json('positions'))->contains(fn ($j) => $j['name'] === 'PRODUCTION OPERATOR' && $j['sub_location'] === 'Jetty' && $j['holders'] === 2));

        // Not out of the plan while it holds positions, nor deleted while in use.
        $this->ops('Site Rantau', [['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => null]])->assertSessionHasErrors('ops');
        $this->delete("/core-hr/settings/sub-locations/{$jetty->id}")->assertSessionHasErrors('name');
    }

    public function test_a_dept_head_moved_to_a_sub_lokasi_leaves_the_team_in_its_departemen(): void
    {
        $rantau = $this->location('Site Rantau');
        $this->post("/core-hr/org-chart/plan/{$rantau->id}/setup", [])->assertSessionHasNoErrors();
        $pm = Position::where('name', 'PROJECT MANAGER RTU')->firstOrFail();
        $this->post('/core-hr/settings/sub-locations', ['name' => 'Jetty', 'work_location_id' => $rantau->id]);
        $jetty = SubLocation::where('name', 'Jetty')->firstOrFail();
        $dept = $this->position('KDP-MTC'); // DEPT HEAD MAINTENANCE
        $spv = $this->position('SPV-MTC');
        $deptHolder = Employee::where('position_id', $dept->id)->firstOrFail();
        $this->ops('Site Rantau', [
            ['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => $pm->id],
            ['type' => 'reparent', 'position' => $spv->id, 'parent' => $dept->id],
        ])->assertSessionHasNoErrors();

        // The Dept Head (and its holder) goes to the Sub Lokasi alone ...
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $dept->id, 'parent' => $pm->id, 'sub_location' => $jetty->id]])->assertSessionHasNoErrors();
        $this->assertSame($jetty->id, $dept->fresh()->sub_location_id);
        $this->assertSame($jetty->id, $deptHolder->fresh()->sub_location_id);

        // ... the team stays at the site, in Departemen MAINTENANCE, under the DEPT HEAD MAINTENANCE left there (vacant).
        $left = Position::where('name', 'DEPT HEAD MAINTENANCE')->whereNull('sub_location_id')->firstOrFail();
        $this->assertNotSame($dept->id, $left->id);
        $spv->refresh();
        $this->assertSame([$left->id, null, 'section:'.Section::where('name', 'WORKSHOP')->value('id')], [$spv->parent_position_id, $spv->sub_location_id, $spv->unitKey()]);
        $this->assertSame([$pm->id, 0], [$left->parent_position_id, $left->employees()->count()]);

        // A departemen can be in the plan more than once: the palette counts both.
        $this->get("/core-hr/org-chart?location={$rantau->id}")->assertInertia(fn (Assert $page) => $page
            ->where('palette.departments', fn ($list) => collect($list)->firstWhere('name', 'MAINTENANCE')['in_plan'] === 2)
            ->where('tree', fn ($tree) => $this->node($tree, 'Jetty')['children'][0]['id'] === $dept->id));

        // Back to the site where the same position is: refused, the person is moved with Mutasi instead.
        $this->ops('Site Rantau', [['type' => 'reparent', 'position' => $dept->id, 'parent' => $pm->id, 'sub_location' => null]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'pakai Mutasi di Mode MPP');
    }

    public function test_people_are_listed_by_sub_group_and_placed_in_the_chart(): void
    {
        $rina = $this->named('Rina Marlina'); // OFFICER EMPLOYEE ADMINISTRATION, HO
        $ho = $this->location('Head Office');

        // Taken off: she keeps her placement and waits in the list.
        $this->post("/core-hr/employees/{$rina->id}/remove")->assertSessionHasNoErrors();
        $rina->refresh();
        $this->assertSame([null, Employee::STATUS_ACTIVE], [$rina->position_id, $rina->status]);
        $this->get("/core-hr/org-chart?location={$ho->id}")->assertInertia(fn (Assert $page) => $page
            ->component('CoreHr/OrgChart/Index')
            ->where('people', fn ($people) => collect($people)->firstWhere('id', $rina->id) === [
                'id' => $rina->id, 'employee_number' => $rina->employee_number, 'name' => 'Rina Marlina', 'status' => Employee::STATUS_ACTIVE,
                'job_level_id' => $rina->job_level_id, 'job_level' => 'Officer HO/ Group Leader', 'layer' => 2,
                'department' => 'HRGA', 'section' => 'PERSONALIA', 'discipline' => 'EMPLOYEE ADMINISTRATION',
                'sub_location_id' => null, 'sub_location' => null, 'position_id' => null, 'position_name' => null,
            ]));
        $this->get('/core-hr/employees/full-data')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $rina->id)['job_level'] === 'Officer HO/ Group Leader'));

        // Dropped on a position: she takes its placement.
        $fin = $this->position('STF-FIN'); // JUNIOR STAFF ACCOUNTING, Section AKUNTANSI, HO
        $this->put("/core-hr/employees/{$rina->id}/position", ['position_id' => $fin->id])->assertSessionHasNoErrors();
        $rina->refresh();
        $this->assertSame([$fin->id, 'Junior Staff', 'AKUNTANSI', 'ACCOUNTING'], [$rina->position_id, $rina->jobLevel->name, $rina->section->name, $rina->discipline]);

        // SubGroup layer 3 and up takes one active holder.
        $this->put("/core-hr/employees/{$rina->id}/position", ['position_id' => $this->position('KDP-HRG')->id])->assertSessionHasErrors('position_id');
        $this->assertErrorContains('position_id', 'Siti Rahmawati');

        // The profile shows the superior through the structure: the Finance manager.
        $this->get("/core-hr/employees/{$rina->id}/profile")->assertInertia(fn (Assert $page) => $page
            ->where('employee.position_id', $fin->id)
            ->where('employee.superior.position', 'MANAGER FINANCE')
            ->where('employee.superior.names', ['Dewi Lestari']));
    }

    public function test_viewer_reads_the_charts_but_cannot_change_them(): void
    {
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());
        $ho = $this->location('Head Office');

        $this->get("/core-hr/org-chart?location={$ho->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)->where('palette', null));
        $this->get("/core-hr/org-chart?location={$ho->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)->where('people', [])->missing('placement_options'));

        $this->ops('Head Office', [['type' => 'delete', 'position' => $this->position('STF-FIN')->id]])->assertForbidden();
        $this->post("/core-hr/org-chart/plan/{$ho->id}/setup", [])->assertForbidden();
        $this->post("/core-hr/employees/{$this->named('Rina Marlina')->id}/remove")->assertForbidden();
    }

    public function test_sections_and_the_superior_through_the_structure(): void
    {
        $maintenance = Department::where('name', 'MAINTENANCE')->firstOrFail();
        $this->post('/core-hr/settings/section', ['name' => 'PLANT', 'department_id' => $maintenance->id])->assertSessionHasNoErrors();
        $this->post('/core-hr/settings/section', ['name' => 'plant', 'department_id' => $maintenance->id])->assertSessionHasErrors('name');
        $workshop = Section::where('name', 'WORKSHOP')->firstOrFail();
        $this->delete("/core-hr/settings/section/{$workshop->id}")->assertSessionHasErrors('name');
        $this->delete("/core-hr/settings/department/{$maintenance->id}")->assertSessionHasErrors('name');

        // Staff → their Section Head → the Dept Head at the same site → the GM of the divisi.
        $this->assertSame('SECTION HEAD WORKSHOP', $this->position('MKN-MTC')->superior()?->name);
        $this->assertSame('DEPT HEAD MAINTENANCE', $this->position('SPV-MTC')->superior()?->name);
        $this->assertSame('GENERAL MANAGER OPERASIONAL', $this->position('KDP-MTC')->superior()?->name);

        // Data Lengkap shows the units; a divisi-level position has no departemen.
        $hendra = Employee::where('position_id', $this->position('KDV-OPS')->id)->firstOrFail();
        $joko = Employee::where('position_id', $this->position('SPV-MTC')->id)->firstOrFail();
        $rows = collect($this->get('/core-hr/employees/full-data')->inertiaProps('rows'))->keyBy('id');
        $this->assertSame(['WORKSHOP', 'MAINTENANCE', 'OPERASIONAL'], [$rows[$joko->id]['section_name'], $rows[$joko->id]['department_name'], $rows[$joko->id]['division_name']]);
        $this->assertSame([null, null, 'OPERASIONAL', 'Direktur Utama'], [$rows[$hendra->id]['department_name'], $rows[$hendra->id]['section_name'], $rows[$hendra->id]['division_name'], $rows[$hendra->id]['directorate_name']]);
    }

    public function test_bod_master_data(): void
    {
        $this->post('/core-hr/settings/directorate', ['name' => 'Komisaris'])->assertSessionHasNoErrors();
        $this->assertTrue(Directorate::where('name', 'Komisaris')->exists());

        $this->delete('/core-hr/settings/directorate/'.Directorate::where('name', 'Direktur Utama')->value('id'))->assertSessionHasErrors('name');
    }

    public function test_jabatan_excel_import_composes_names_from_placements(): void
    {
        $this->get('/core-hr/settings/position/excel/template')->assertOk();

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Work Location *', 'SubGroup *', 'BOD', 'Divisi', 'Departemen', 'Section', 'Nama Jabatan (Bidang)'],
            ['Head Office', 'Section Head HO', '', '', '', 'PERSONALIA', ''],
            ['Site Rantau', 'Officer HO/ Group Leader', '', '', '', 'WORKSHOP', 'HSE FIELD'],
            ['HO', 'Officer HO/ Group Leader', '', '', '', 'PERSONALIA', 'hse field'],
            ['Head Office', 'Manager', '', '', 'HRGA', '', ''],
            ['Head Office', 'Section Head HO', '', '', '', 'TIDAK ADA', ''],
            // Named after the place; its superior is arranged in the chart.
            ['Site Murung Raya', 'Project Manager', '', '', '', '', ''],
            ['Head Office', 'Section Head HO', '', '', '', 'PERSONALIA', ''],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'pos').'.xlsx';
        (new Xlsx($book))->save($path);
        $before = Position::count();

        // 1. Preview: every row with what would happen — nothing is saved.
        $rows = collect($this->post('/core-hr/settings/position/excel/preview', [
            'file' => new UploadedFile($path, 'position.xlsx', null, null, true),
        ])->assertOk()->json('rows'))->keyBy('row');
        $this->assertSame($before, Position::count());
        $this->assertSame(
            [2 => 'new', 3 => 'new', 4 => 'new', 5 => 'skipped', 6 => 'error', 7 => 'new', 8 => 'skipped'],
            $rows->map(fn ($r) => $r['status'])->all(),
        );
        $this->assertSame(['SECTION HEAD PERSONALIA', 'Section PERSONALIA · HRGA'], [$rows[2]['name'], $rows[2]['unit']]);
        $this->assertStringContainsString('Sudah ada di Head Office', $rows[5]['reason']);
        $this->assertStringContainsString('Section "TIDAK ADA" tidak ada', $rows[6]['reason']);
        $this->assertSame('Sama dengan baris 2 di file ini.', $rows[8]['reason']);

        // 2. Save the ready rows.
        $ready = $rows->where('status', 'new')->map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']])->values()->all();
        $this->postJson('/core-hr/settings/position/excel/import', ['rows' => $ready])->assertOk()->assertJsonPath('created', 4);
        $this->assertSame($before + 4, Position::count());
        $this->assertTrue(Position::where('name', 'SECTION HEAD PERSONALIA')->exists());

        // 3. Failed rows come back as the template, with their reason, ready to fix and import again.
        $download = $this->postJson('/core-hr/settings/position/excel/fix', [
            'rows' => [['row' => 6, 'values' => $rows[6]['values'], 'reason' => $rows[6]['reason']]],
        ])->assertOk();
        $fixPath = tempnam(sys_get_temp_dir(), 'fix').'.xlsx';
        file_put_contents($fixPath, $download->streamedContent());
        $sheet = IOFactory::load($fixPath)->getSheet(0);
        $this->assertSame(['Work Location *', 'Job Level *', 'Directorate', 'Division', 'Department', 'Section', 'Discipline (Position Name)', 'Error Reason', 'Source Row'], $sheet->rangeToArray('A1:I1')[0]);
        $this->assertSame(['Head Office', 'Section Head HO', 'TIDAK ADA'], [$sheet->getCell('A2')->getValue(), $sheet->getCell('B2')->getValue(), $sheet->getCell('F2')->getValue()]);
        $this->assertStringContainsString('tidak ada', $sheet->getCell('H2')->getValue());

        $sheet->setCellValue('F2', 'WORKSHOP'); // fixed; the reason columns can stay
        (new Xlsx($sheet->getParent()))->save($fixPath);
        $this->post('/core-hr/settings/position/excel/preview', ['file' => new UploadedFile($fixPath, 'fix.xlsx', null, null, true)])
            ->assertOk()->assertJsonPath('rows.0.status', 'new')->assertJsonPath('rows.0.row', 2);
        $this->assertSame(Position::NO_UNIT, Position::where('name', 'PROJECT MANAGER MRY')->firstOrFail()->unitKey());
        // Officer at Head Office, Group Leader elsewhere.
        $this->assertTrue(Position::where('name', 'GROUP LEADER HSE FIELD')->where('work_location_id', WorkLocation::where('name', 'Site Rantau')->value('id'))->exists());
        $this->assertTrue(Position::where('name', 'OFFICER HSE FIELD')->where('work_location_id', WorkLocation::where('name', 'Head Office')->value('id'))->exists());
    }
}
