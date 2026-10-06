<?php

namespace Tests\Feature\Manpower;

use App\Models\Bod;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\Section;
use App\Models\SubGroup;
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

    private function jabatan(string $key): Jabatan
    {
        return $this->seededJabatan($key);
    }

    private function named(string $name): Karyawan
    {
        return Karyawan::where('name', $name)->firstOrFail();
    }

    private function assertErrorContains(string $key, string $needle): void
    {
        $this->assertStringContainsString($needle, session('errors')->first($key));
    }

    /** A placement: SubGroup + work location + unit (by level and name) + bidang. */
    private function placement(string $subGroup, string $location, ?string $level = null, ?string $unit = null, ?string $bidang = null): array
    {
        $models = ['bod' => Bod::class, 'divisi' => Divisi::class, 'departemen' => Departemen::class, 'section' => Section::class];

        return array_filter([
            'sub_group_id' => SubGroup::where('nama', $subGroup)->value('id'),
            'work_location_id' => WorkLocation::where('name', $location)->value('id'),
            $level ? "{$level}_id" : null => $level ? $models[$level]::where('name', $unit)->value('id') : null,
            'bidang' => $bidang,
        ], fn ($v, $k) => $k !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function location(string $name): WorkLocation
    {
        return WorkLocation::where('name', $name)->firstOrFail();
    }

    /** Changes made in the plan of a work location. */
    private function ops(string $location, array $ops): TestResponse
    {
        return $this->post('/manpower/org-structure/plan/'.$this->location($location)->id.'/ops', ['ops' => $ops]);
    }

    private function createOp(string $temp, array $placement, int|string|null $parent = null): array
    {
        return ['type' => 'create', 'temp_id' => $temp, 'penempatan' => $placement, 'parent' => $parent];
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
        $this->get('/manpower/org-structure?lokasi='.$this->location('Head Office')->id)->assertInertia(fn (Assert $page) => $page
            ->component('Manpower/OrgStructure/Index')
            ->where('needs_setup', false)
            ->has('people')
            ->where('tree', fn ($tree) => collect($this->names($tree->toArray()))->contains('MANAGER HRGA'))
            // The sites are not set up yet: their cards wait apart from the HO chart.
            ->where('unlinked_sites', fn ($sites) => collect($sites)->pluck('work_location.name')->all() === ['Site Murung Raya', 'Site Rantau']
                && collect($sites)->every(fn ($s) => $s['jabatan'] === null))
            ->has('palette.bods', 1)
            ->has('palette.layers', 2)
            ->where('palette.unit_sub_groups.departemen', SubGroup::where('nama', 'Manager')->value('id'))
            ->where('palette.unit_sub_groups.section', SubGroup::where('nama', 'Section Head HO')->value('id')));

        // A site starts with its Project Manager.
        $this->get('/manpower/org-structure?lokasi='.$this->location('Site Rantau')->id)->assertInertia(fn (Assert $page) => $page
            ->where('needs_setup', true)
            ->where('setup.pm_sub_group', 'Project Manager')
            ->where('setup.dpm_sub_group', 'Deputy Project Manager')
            ->where('tree', []));

        // The old address of the plan opens Mode Organisasi.
        $rantau = $this->location('Site Rantau')->id;
        $this->get("/manpower/org-structure/plan?lokasi={$rantau}")->assertRedirect("/manpower/org-structure?lokasi={$rantau}&mode=organisasi");
    }

    public function test_a_site_is_set_up_from_its_project_manager(): void
    {
        $site = $this->location('Site Murung Raya');
        $irfan = Karyawan::create([
            'nrp' => '10115900', 'name' => 'Irfan', 'status' => Karyawan::STATUS_AKTIF, 'work_location_id' => $site->id,
            'sub_group_id' => SubGroup::where('nama', 'Project Manager')->value('id'),
        ]);

        $this->post("/manpower/org-structure/plan/{$site->id}/setup", [
            'pm_karyawan_id' => $irfan->id, 'deputy' => true, 'dpm_karyawan_id' => null, 'atasan_jabatan_id' => $this->jabatan('KDP-HRG')->id,
        ])->assertSessionHasNoErrors();

        $pm = Jabatan::where('name', 'PROJECT MANAGER MRY')->firstOrFail();
        $this->assertSame([$pm->id, 'MANAGER HRGA'], [$irfan->fresh()->jabatan_id, $pm->atasan()?->name]);
        $this->assertSame($pm->id, Jabatan::where('name', 'DEPUTY PROJECT MANAGER MRY')->value('parent_jabatan_id'), 'a vacant Deputy under the PM');
        // Positions of the site without a superior now report to the PM — except a more senior one.
        $this->assertSame($pm->id, $this->jabatan('KDP-PRD')->parent_jabatan_id);
        $this->assertNull($this->jabatan('KDV-OPS')->parent_jabatan_id, 'a General Manager (layer 5) is not put under a PM (layer 4)');

        // HO shows the site under the PM's superior; the site's chart starts at the PM.
        $this->get('/manpower/org-structure?lokasi='.$this->location('Head Office')->id)->assertInertia(fn (Assert $page) => $page
            ->where('tree', fn ($tree) => collect($tree)->contains(fn ($n) => ($n['name'] ?? '') === 'MANAGER HRGA'
                && collect($n['children'])->contains(fn ($c) => $c['kind'] === 'site' && $c['jabatan']['holders'][0]['name'] === 'Irfan'))));
        $this->get("/manpower/org-structure?lokasi={$site->id}")->assertInertia(fn (Assert $page) => $page
            ->where('needs_setup', false)
            ->where('tree.0.name', 'PROJECT MANAGER MRY'));

        $this->post("/manpower/org-structure/plan/{$site->id}/setup", [])->assertSessionHasErrors('setup');
    }

    public function test_the_plan_adds_positions_and_keeps_the_layer_rule(): void
    {
        $spv = $this->jabatan('SPV-MTC'); // SECTION HEAD WORKSHOP, Site Rantau
        $rantau = $this->location('Site Rantau')->id;
        $before = Jabatan::count();

        // A section dropped under the Dept Head: its Section Head (the site's SubGroup for sections).
        $this->ops('Site Rantau', [
            $this->createOp('temp-a', ['work_location_id' => $rantau, 'sub_group_id' => SubGroup::where('nama', 'Section Head Site')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id')], $this->jabatan('KDP-MTC')->id),
            // A Layer 2 title under it, in its section.
            $this->createOp('temp-b', ['work_location_id' => $rantau, 'sub_group_id' => SubGroup::where('nama', 'Officer HO/ Group Leader')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id'), 'bidang' => 'HSE FIELD'], 'temp-a'),
        ])->assertSessionHasNoErrors();
        $gl = Jabatan::where('name', 'GROUP LEADER HSE FIELD')->firstOrFail();
        $this->assertSame([$before + 2, 'SECTION HEAD PERSONALIA'], [Jabatan::count(), $gl->parent?->name]);

        // Once is enough; and never under a lower layer.
        $this->ops('Site Rantau', [$this->createOp('temp-c', ['work_location_id' => $rantau, 'sub_group_id' => SubGroup::where('nama', 'Section Head Site')->value('id'), 'section_id' => Section::where('name', 'PERSONALIA')->value('id')])])
            ->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'sudah ada');
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $spv->id, 'parent' => $gl->id]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'layer atasan tidak boleh di bawah bawahannya');
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $gl->parent_jabatan_id, 'parent' => $gl->id]])->assertSessionHasErrors('ops');

        // A Layer 1–2 title joins the unit of its new superior; a Section Head keeps its own.
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $gl->id, 'parent' => $spv->id]])->assertSessionHasNoErrors();
        $this->assertSame('section:'.Section::where('name', 'WORKSHOP')->value('id'), $gl->fresh()->unitKey());
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $spv->id, 'parent' => Jabatan::where('name', 'SECTION HEAD PERSONALIA')->value('id')]])->assertSessionHasNoErrors();
        $this->assertSame(['SECTION HEAD WORKSHOP', 'section:'.Section::where('name', 'WORKSHOP')->value('id')], [$spv->fresh()->name, $spv->fresh()->unitKey()]);

        // Positions with someone on them, or with subordinates, stay.
        $this->ops('Site Rantau', [['type' => 'delete', 'jabatan' => $this->jabatan('MKN-MTC')->id]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'Rudi Hartono');
        $this->ops('Site Rantau', [['type' => 'delete', 'jabatan' => $spv->id]])->assertSessionHasErrors('ops');
        $this->ops('Site Rantau', [['type' => 'delete', 'jabatan' => $gl->id]])->assertSessionHasNoErrors();
        $this->assertModelMissing($gl);

        // A SubGroup's layer cannot change so that a line turns upside down.
        $update = fn (string $nama, int $layer) => $this->put('/manpower/master-data/sub-group/'.SubGroup::where('nama', $nama)->value('id'), [
            ...SubGroup::where('nama', $nama)->first(['nama', 'sumber', 'gelar', 'gelar_ho'])->toArray(),
            'layer' => $layer,
        ]);
        $update('Section Head Site', 4)->assertSessionHasErrors('layer');
        $this->assertErrorContains('layer', 'lebih tinggi dari atasannya DEPT HEAD MAINTENANCE');
    }

    public function test_sub_lokasi_has_its_own_box_and_positions(): void
    {
        $rantau = $this->location('Site Rantau');
        $this->post("/manpower/org-structure/plan/{$rantau->id}/setup", [])->assertSessionHasNoErrors();
        $this->post('/manpower/master-data/sub-lokasi', ['name' => 'Jetty', 'work_location_id' => $rantau->id])->assertSessionHasNoErrors();
        $this->post('/manpower/master-data/sub-lokasi', ['name' => 'jetty', 'work_location_id' => $rantau->id])->assertSessionHasErrors('name');
        $jetty = SubLocation::where('name', 'Jetty')->firstOrFail();
        $dept = $this->jabatan('KDP-MTC');

        // The box hangs under the Dept Head; a MECHANIC there is another position than the one at the site.
        $this->ops('Site Rantau', [
            ['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => $dept->id],
            $this->createOp('temp-a', [
                'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id, 'sub_group_id' => SubGroup::where('nama', 'Mekanik')->value('id'),
                'section_id' => Section::where('name', 'WORKSHOP')->value('id'), 'bidang' => 'MECHANIC',
            ], $dept->id),
        ])->assertSessionHasNoErrors();
        $there = Jabatan::where('sub_location_id', $jetty->id)->firstOrFail();
        $this->assertSame('MECHANIC', $there->name);
        $this->assertNotSame($this->jabatan('MKN-MTC')->id, $there->id);

        $this->get("/manpower/org-structure?lokasi={$rantau->id}")->assertInertia(fn (Assert $page) => $page
            ->where('tree.0.name', 'PROJECT MANAGER RTU')
            ->where('tree', fn ($tree) => $this->node($tree, 'Jetty')['children'][0]['name'] === 'MECHANIC'
                && collect($this->node($tree, 'DEPT HEAD MAINTENANCE')['children'])->contains(fn ($c) => $c['kind'] === 'sub_location'))
            ->where('palette.sub_locations.0', ['id' => $jetty->id, 'name' => 'Jetty', 'in_plan' => true]));

        // Someone assigned there fills it; the same placement without the Sub Lokasi is the site's own.
        $this->post('/manpower/karyawan', [
            'nrp' => '10124310', 'name' => 'Ujang', 'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id,
            'sub_group_id' => SubGroup::where('nama', 'Mekanik')->value('id'), 'section_id' => Section::where('name', 'WORKSHOP')->value('id'), 'bidang' => 'MECHANIC',
        ])->assertSessionHasNoErrors();
        $this->assertSame([$there->id, $jetty->id], [Karyawan::where('name', 'Ujang')->value('jabatan_id'), Karyawan::where('name', 'Ujang')->value('sub_location_id')]);

        // Dropped on the box (or "Mutasi"): Rudi, the site's mechanic, moves to the MECHANIC there.
        $rudi = $this->named('Rudi Hartono');
        $this->post("/manpower/karyawan/{$rudi->id}/sub-lokasi", ['sub_location_id' => $jetty->id])->assertSessionHasNoErrors();
        $this->assertSame([$there->id, $jetty->id], [$rudi->fresh()->jabatan_id, $rudi->fresh()->sub_location_id]);

        // Without a matching position there, one is made — under the position the box hangs from.
        $operator = ['sub_group_id' => SubGroup::where('nama', 'Operator')->value('id'), 'section_id' => Section::where('name', 'OPERASI TAMBANG')->value('id'), 'bidang' => 'PRODUCTION OPERATOR'];
        $this->post('/manpower/karyawan', ['nrp' => '10124311', 'name' => 'Wawan', 'work_location_id' => $rantau->id, ...$operator])->assertSessionHasNoErrors();
        $wawan = $this->named('Wawan');
        $this->assertNull($wawan->jabatan_id, 'not in the site plan: waiting');
        $this->post("/manpower/karyawan/{$wawan->id}/sub-lokasi", ['sub_location_id' => $jetty->id])->assertSessionHasNoErrors();
        $made = $wawan->fresh()->jabatan;
        $this->assertSame(['PRODUCTION OPERATOR', $jetty->id, $dept->id], [$made->name, $made->sub_location_id, $made->parent_jabatan_id]);
        // The same from the form: a placement at the Sub Lokasi fills (or makes) the position there.
        $this->post('/manpower/karyawan', ['nrp' => '10124312', 'name' => 'Yanto', 'work_location_id' => $rantau->id, 'sub_location_id' => $jetty->id, ...$operator])
            ->assertSessionHasNoErrors();
        $this->assertSame($made->id, $this->named('Yanto')->jabatan_id);

        // "Mutasi" lists a location's positions and Sub Lokasi.
        $positions = $this->getJson("/manpower/org-structure/positions?lokasi={$rantau->id}")->assertOk()->assertJsonPath('sub_locations.0.name', 'Jetty');
        $this->assertTrue(collect($positions->json('positions'))->contains(fn ($j) => $j['name'] === 'PRODUCTION OPERATOR' && $j['sub_location'] === 'Jetty' && $j['holders'] === 2));

        // Not out of the plan while it holds positions, nor deleted while in use.
        $this->ops('Site Rantau', [['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => null]])->assertSessionHasErrors('ops');
        $this->delete("/manpower/master-data/sub-lokasi/{$jetty->id}")->assertSessionHasErrors('name');
    }

    public function test_a_dept_head_moved_to_a_sub_lokasi_leaves_the_team_in_its_departemen(): void
    {
        $rantau = $this->location('Site Rantau');
        $this->post("/manpower/org-structure/plan/{$rantau->id}/setup", [])->assertSessionHasNoErrors();
        $pm = Jabatan::where('name', 'PROJECT MANAGER RTU')->firstOrFail();
        $this->post('/manpower/master-data/sub-lokasi', ['name' => 'Jetty', 'work_location_id' => $rantau->id]);
        $jetty = SubLocation::where('name', 'Jetty')->firstOrFail();
        $dept = $this->jabatan('KDP-MTC'); // DEPT HEAD MAINTENANCE
        $spv = $this->jabatan('SPV-MTC');
        $deptHolder = Karyawan::where('jabatan_id', $dept->id)->firstOrFail();
        $this->ops('Site Rantau', [
            ['type' => 'sub_location', 'sub_location' => $jetty->id, 'parent' => $pm->id],
            ['type' => 'reparent', 'jabatan' => $spv->id, 'parent' => $dept->id],
        ])->assertSessionHasNoErrors();

        // The Dept Head (and its holder) goes to the Sub Lokasi alone ...
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $dept->id, 'parent' => $pm->id, 'sub_location' => $jetty->id]])->assertSessionHasNoErrors();
        $this->assertSame($jetty->id, $dept->fresh()->sub_location_id);
        $this->assertSame($jetty->id, $deptHolder->fresh()->sub_location_id);

        // ... the team stays at the site, in Departemen MAINTENANCE, under the DEPT HEAD MAINTENANCE left there (vacant).
        $left = Jabatan::where('name', 'DEPT HEAD MAINTENANCE')->whereNull('sub_location_id')->firstOrFail();
        $this->assertNotSame($dept->id, $left->id);
        $spv->refresh();
        $this->assertSame([$left->id, null, 'section:'.Section::where('name', 'WORKSHOP')->value('id')], [$spv->parent_jabatan_id, $spv->sub_location_id, $spv->unitKey()]);
        $this->assertSame([$pm->id, 0], [$left->parent_jabatan_id, $left->karyawans()->count()]);

        // A departemen can be in the plan more than once: the palette counts both.
        $this->get("/manpower/org-structure?lokasi={$rantau->id}")->assertInertia(fn (Assert $page) => $page
            ->where('palette.departemens', fn ($list) => collect($list)->firstWhere('name', 'MAINTENANCE')['in_plan'] === 2)
            ->where('tree', fn ($tree) => $this->node($tree, 'Jetty')['children'][0]['id'] === $dept->id));

        // Back to the site where the same position is: refused, the person is moved with Mutasi instead.
        $this->ops('Site Rantau', [['type' => 'reparent', 'jabatan' => $dept->id, 'parent' => $pm->id, 'sub_location' => null]])->assertSessionHasErrors('ops');
        $this->assertErrorContains('ops', 'pakai Mutasi di Mode MPP');
    }

    public function test_people_are_listed_by_sub_group_and_placed_in_the_chart(): void
    {
        $rina = $this->named('Rina Marlina'); // OFFICER EMPLOYEE ADMINISTRATION, HO
        $ho = $this->location('Head Office');

        // Taken off: she keeps her placement and waits in the list.
        $this->post("/manpower/karyawan/{$rina->id}/copot")->assertSessionHasNoErrors();
        $rina->refresh();
        $this->assertSame([null, Karyawan::STATUS_AKTIF], [$rina->jabatan_id, $rina->status]);
        $this->get("/manpower/org-structure?lokasi={$ho->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Manpower/OrgStructure/Index')
            ->where('people', fn ($people) => collect($people)->firstWhere('id', $rina->id) === [
                'id' => $rina->id, 'nrp' => $rina->nrp, 'name' => 'Rina Marlina', 'status' => 'Aktif',
                'sub_group_id' => $rina->sub_group_id, 'sub_group' => 'Officer HO/ Group Leader', 'layer' => 2,
                'departemen' => 'HRGA', 'section' => 'PERSONALIA', 'bidang' => 'EMPLOYEE ADMINISTRATION',
                'sub_location_id' => null, 'sub_location' => null, 'jabatan_id' => null, 'jabatan_name' => null,
            ]));
        $this->get('/manpower/karyawan/data-lengkap')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $rina->id)['sub_group'] === 'Officer HO/ Group Leader'));

        // Dropped on a position: she takes its placement.
        $fin = $this->jabatan('STF-FIN'); // JUNIOR STAFF ACCOUNTING, Section AKUNTANSI, HO
        $this->put("/manpower/karyawan/{$rina->id}/jabatan", ['jabatan_id' => $fin->id])->assertSessionHasNoErrors();
        $rina->refresh();
        $this->assertSame([$fin->id, 'Junior Staff', 'AKUNTANSI', 'ACCOUNTING'], [$rina->jabatan_id, $rina->subGroup->nama, $rina->section->name, $rina->bidang]);

        // SubGroup layer 3 and up takes one active holder.
        $this->put("/manpower/karyawan/{$rina->id}/jabatan", ['jabatan_id' => $this->jabatan('KDP-HRG')->id])->assertSessionHasErrors('jabatan_id');
        $this->assertErrorContains('jabatan_id', 'Siti Rahmawati');

        // The profile shows the superior through the structure: the Finance manager.
        $this->get("/manpower/karyawan/{$rina->id}/profil")->assertInertia(fn (Assert $page) => $page
            ->where('karyawan.jabatan_id', $fin->id)
            ->where('karyawan.atasan.jabatan', 'MANAGER FINANCE')
            ->where('karyawan.atasan.names', ['Dewi Lestari']));
    }

    public function test_viewer_reads_the_charts_but_cannot_change_them(): void
    {
        $this->actingAs(User::where('email', 'viewer@hrpuspita.local')->firstOrFail());
        $ho = $this->location('Head Office');

        $this->get("/manpower/org-structure?lokasi={$ho->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)->where('palette', null));
        $this->get("/manpower/org-structure?lokasi={$ho->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)->where('people', [])->missing('penempatan_options'));

        $this->ops('Head Office', [['type' => 'delete', 'jabatan' => $this->jabatan('STF-FIN')->id]])->assertForbidden();
        $this->post("/manpower/org-structure/plan/{$ho->id}/setup", [])->assertForbidden();
        $this->post("/manpower/karyawan/{$this->named('Rina Marlina')->id}/copot")->assertForbidden();
    }

    public function test_sections_and_the_superior_through_the_structure(): void
    {
        $maintenance = Departemen::where('name', 'MAINTENANCE')->firstOrFail();
        $this->post('/manpower/master-data/section', ['name' => 'PLANT', 'departemen_id' => $maintenance->id])->assertSessionHasNoErrors();
        $this->post('/manpower/master-data/section', ['name' => 'plant', 'departemen_id' => $maintenance->id])->assertSessionHasErrors('name');
        $workshop = Section::where('name', 'WORKSHOP')->firstOrFail();
        $this->delete("/manpower/master-data/section/{$workshop->id}")->assertSessionHasErrors('name');
        $this->delete("/manpower/master-data/departemen/{$maintenance->id}")->assertSessionHasErrors('name');

        // Staff → their Section Head → the Dept Head at the same site → the GM of the divisi.
        $this->assertSame('SECTION HEAD WORKSHOP', $this->jabatan('MKN-MTC')->atasan()?->name);
        $this->assertSame('DEPT HEAD MAINTENANCE', $this->jabatan('SPV-MTC')->atasan()?->name);
        $this->assertSame('GENERAL MANAGER OPERASIONAL', $this->jabatan('KDP-MTC')->atasan()?->name);

        // Data Lengkap shows the units; a divisi-level position has no departemen.
        $hendra = Karyawan::where('jabatan_id', $this->jabatan('KDV-OPS')->id)->firstOrFail();
        $joko = Karyawan::where('jabatan_id', $this->jabatan('SPV-MTC')->id)->firstOrFail();
        $rows = collect($this->get('/manpower/karyawan/data-lengkap')->inertiaProps('rows'))->keyBy('id');
        $this->assertSame(['WORKSHOP', 'MAINTENANCE', 'OPERASIONAL'], [$rows[$joko->id]['section_name'], $rows[$joko->id]['departemen_name'], $rows[$joko->id]['divisi_name']]);
        $this->assertSame([null, null, 'OPERASIONAL', 'Direktur Utama'], [$rows[$hendra->id]['departemen_name'], $rows[$hendra->id]['section_name'], $rows[$hendra->id]['divisi_name'], $rows[$hendra->id]['bod_name']]);
    }

    public function test_bod_master_data(): void
    {
        $this->post('/manpower/master-data/bod', ['name' => 'Komisaris'])->assertSessionHasNoErrors();
        $this->assertTrue(Bod::where('name', 'Komisaris')->exists());

        $this->delete('/manpower/master-data/bod/'.Bod::where('name', 'Direktur Utama')->value('id'))->assertSessionHasErrors('name');
    }

    public function test_jabatan_excel_import_composes_names_from_placements(): void
    {
        $this->get('/manpower/master-data/jabatan/excel/template')->assertOk();

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
        $path = tempnam(sys_get_temp_dir(), 'jab').'.xlsx';
        (new Xlsx($book))->save($path);
        $before = Jabatan::count();

        // 1. Preview: every row with what would happen — nothing is saved.
        $rows = collect($this->post('/manpower/master-data/jabatan/excel/preview', [
            'file' => new UploadedFile($path, 'jabatan.xlsx', null, null, true),
        ])->assertOk()->json('rows'))->keyBy('row');
        $this->assertSame($before, Jabatan::count());
        $this->assertSame(
            [2 => 'baru', 3 => 'baru', 4 => 'baru', 5 => 'dilewati', 6 => 'error', 7 => 'baru', 8 => 'dilewati'],
            $rows->map(fn ($r) => $r['status'])->all(),
        );
        $this->assertSame(['SECTION HEAD PERSONALIA', 'Section PERSONALIA · HRGA'], [$rows[2]['name'], $rows[2]['unit']]);
        $this->assertStringContainsString('Sudah ada di Head Office', $rows[5]['reason']);
        $this->assertStringContainsString('Section "TIDAK ADA" tidak ada', $rows[6]['reason']);
        $this->assertSame('Sama dengan baris 2 di file ini.', $rows[8]['reason']);

        // 2. Save the ready rows.
        $ready = $rows->where('status', 'baru')->map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']])->values()->all();
        $this->postJson('/manpower/master-data/jabatan/excel/import', ['rows' => $ready])->assertOk()->assertJsonPath('created', 4);
        $this->assertSame($before + 4, Jabatan::count());
        $this->assertTrue(Jabatan::where('name', 'SECTION HEAD PERSONALIA')->exists());

        // 3. Failed rows come back as the template, with their reason, ready to fix and import again.
        $download = $this->postJson('/manpower/master-data/jabatan/excel/perbaikan', [
            'rows' => [['row' => 6, 'values' => $rows[6]['values'], 'reason' => $rows[6]['reason']]],
        ])->assertOk();
        $fixPath = tempnam(sys_get_temp_dir(), 'fix').'.xlsx';
        file_put_contents($fixPath, $download->streamedContent());
        $sheet = IOFactory::load($fixPath)->getSheet(0);
        $this->assertSame(['Work Location *', 'SubGroup *', 'BOD', 'Divisi', 'Departemen', 'Section', 'Nama Jabatan (Bidang)', 'Alasan Error', 'Baris Asal'], $sheet->rangeToArray('A1:I1')[0]);
        $this->assertSame(['Head Office', 'Section Head HO', 'TIDAK ADA'], [$sheet->getCell('A2')->getValue(), $sheet->getCell('B2')->getValue(), $sheet->getCell('F2')->getValue()]);
        $this->assertStringContainsString('tidak ada', $sheet->getCell('H2')->getValue());

        $sheet->setCellValue('F2', 'WORKSHOP'); // fixed; the reason columns can stay
        (new Xlsx($sheet->getParent()))->save($fixPath);
        $this->post('/manpower/master-data/jabatan/excel/preview', ['file' => new UploadedFile($fixPath, 'perbaikan.xlsx', null, null, true)])
            ->assertOk()->assertJsonPath('rows.0.status', 'baru')->assertJsonPath('rows.0.row', 2);
        $this->assertSame(Jabatan::NO_UNIT, Jabatan::where('name', 'PROJECT MANAGER MRY')->firstOrFail()->unitKey());
        // Officer at Head Office, Group Leader elsewhere.
        $this->assertTrue(Jabatan::where('name', 'GROUP LEADER HSE FIELD')->where('work_location_id', WorkLocation::where('name', 'Site Rantau')->value('id'))->exists());
        $this->assertTrue(Jabatan::where('name', 'OFFICER HSE FIELD')->where('work_location_id', WorkLocation::where('name', 'Head Office')->value('id'))->exists());
    }
}
