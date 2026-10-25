<?php

namespace Tests\Feature\CoreHr;

use App\Models\Directorate;
use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Positions composed from where an employee is placed: Work Location → SubGroup → unit / bidang. */
class PlacementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/core-hr/employees';

    private int $employee_number = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function location(string $name): int
    {
        return WorkLocation::where('name', $name)->value('id');
    }

    private function sub(string $nama): int
    {
        return JobLevel::where('name', $nama)->value('id');
    }

    /** Adds an employee with a placement; returns the created employee (or null when refused). */
    private function add(string $name, array $placement): ?Employee
    {
        $this->post(self::BASE, ['employee_number' => '10126'.$this->employee_number++, 'name' => $name, ...$placement]);

        return Employee::where('name', $name)->with('position.workLocation')->first();
    }

    /** Adds positions to a work location's plan. */
    private function plan(string $location, array ...$placements): void
    {
        $ops = array_map(fn ($pl, $i) => ['type' => 'create', 'temp_id' => "temp-{$i}", 'parent' => null, 'placement' => ['work_location_id' => $this->location($location), ...$pl]], $placements, array_keys($placements));
        $this->post('/core-hr/org-chart/plan/'.$this->location($location).'/ops', ['ops' => $ops])->assertSessionHasNoErrors();
    }

    public function test_an_employee_fills_the_matching_position_of_the_plan_or_waits(): void
    {
        $personalia = Section::where('name', 'PERSONALIA')->value('id');
        $officer = $this->sub('Officer HO/ Group Leader');
        $before = Position::count();

        // OFFICER EMPLOYEE ADMINISTRATION is in the Head Office plan.
        $andi = $this->add('Andi', ['work_location_id' => $this->location('Head Office'), 'job_level_id' => $officer, 'section_id' => $personalia, 'discipline' => 'employee administration']);
        $this->assertSame($this->seededPosition('STF-HRG')->id, $andi->position_id);
        $this->assertSame(['EMPLOYEE ADMINISTRATION', 'HRGA'], [$andi->discipline, $andi->department->name], 'stored as the master list spells it, with the department of the section');

        // The same title at a site is GROUP LEADER EMPLOYEE ADMINISTRATION, not in the Site Rantau plan: Budi waits.
        $budi = $this->add('Budi', ['work_location_id' => $this->location('Site Rantau'), 'job_level_id' => $officer, 'section_id' => $personalia, 'discipline' => 'EMPLOYEE ADMINISTRATION']);
        $this->assertNull($budi->position_id);
        $this->assertSame($before, Position::count(), 'placing people never creates positions');

        // Once it is in the plan, the same placement fills it.
        $this->plan('Site Rantau', ['job_level_id' => $officer, 'section_id' => $personalia, 'discipline' => 'EMPLOYEE ADMINISTRATION']);
        $this->put(self::BASE."/{$budi->id}/placement", $budi->placement())->assertSessionHasNoErrors();
        $this->assertSame('GROUP LEADER EMPLOYEE ADMINISTRATION', $budi->fresh()->position->name);

        // Without a Section: the only position with that title at the place.
        $citra = $this->add('Citra', ['work_location_id' => $this->location('Head Office'), 'job_level_id' => $officer, 'discipline' => 'EMPLOYEE ADMINISTRATION']);
        $this->assertSame($this->seededPosition('STF-HRG')->id, $citra->position_id);

        // A position for one person that is held: the newcomer waits.
        $dodi = $this->add('Dodi', ['work_location_id' => $this->location('Head Office'), 'job_level_id' => $this->sub('Manager'), 'department_id' => Department::where('name', 'HRGA')->value('id')]);
        $this->assertNull($dodi->position_id);
        $this->assertSame('Manager', $dodi->jobLevel->name);

        // What the SubGroup needs must be given.
        $this->post(self::BASE, ['employee_number' => '10126199', 'name' => 'Fajar', 'work_location_id' => $this->location('Head Office'), 'job_level_id' => $this->sub('Section Head HO')])
            ->assertSessionHasErrors(['placement' => 'Pilih Section.']);
        $this->post(self::BASE, ['employee_number' => '10126199', 'name' => 'Fajar', 'work_location_id' => $this->location('Head Office'), 'job_level_id' => $officer, 'section_id' => $personalia, 'discipline' => 'ASTRONAUT'])
            ->assertSessionHasErrors('placement');
        $this->assertStringContainsString('tidak ada di master Nama Jabatan', session('errors')->first('placement'));
    }

    public function test_section_heads_at_ho_and_at_a_site_are_separate_positions(): void
    {
        $workshop = ['section_id' => Section::where('name', 'WORKSHOP')->value('id')];

        // The site's Section Head WORKSHOP is Joko; one at Head Office is another position.
        $this->plan('Head Office', ['job_level_id' => $this->sub('Section Head HO'), ...$workshop]);
        $ho = $this->add('Gita', ['work_location_id' => $this->location('Head Office'), 'job_level_id' => $this->sub('Section Head HO'), ...$workshop]);
        $this->assertSame('SECTION HEAD WORKSHOP', $ho->position->name);
        $this->assertNotSame($this->seededPosition('SPV-MTC')->id, $ho->position_id);

        // Layer 3 and up: one active holder per position; a second one waits.
        $hadi = $this->add('Hadi', ['work_location_id' => $this->location('Site Rantau'), 'job_level_id' => $this->sub('Section Head Site'), ...$workshop]);
        $this->assertNull($hadi->position_id);
    }

    public function test_project_managers_are_named_after_their_site(): void
    {
        $site = $this->location('Site Murung Raya');
        $this->post("/core-hr/org-chart/plan/{$site}/setup", ['deputy' => true, 'superior_position_id' => $this->seededPosition('KDP-HRG')->id])->assertSessionHasNoErrors();

        $irfan = $this->add('Irfan', ['work_location_id' => $site, 'job_level_id' => $this->sub('Project Manager')]);
        $this->assertSame(['PROJECT MANAGER MRY', 'MANAGER HRGA'], [$irfan->position->name, $irfan->position->superior()?->name], 'named with the work location code');
        $joni = $this->add('Joni', ['work_location_id' => $site, 'job_level_id' => $this->sub('Deputy Project Manager')]);
        $this->assertSame(['DEPUTY PROJECT MANAGER MRY', 'PROJECT MANAGER MRY'], [$joni->position->name, $joni->position->superior()?->name]);

        // Never under a lower layer.
        $this->post("/core-hr/org-chart/plan/{$site}/ops", ['ops' => [['type' => 'reparent', 'position' => $irfan->position_id, 'parent' => $this->seededPosition('STF-HRG')->id]]])
            ->assertSessionHasErrors('ops');
        $this->assertStringContainsString('layer atasan tidak boleh di bawah bawahannya', session('errors')->first('ops'));

        // A new code renames the positions named after the place.
        $this->put('/core-hr/settings/work-locations/'.$site, ['name' => 'Site Murung Raya', 'code' => 'RMTU', 'type' => WorkLocation::TYPE_SITE])->assertSessionHasNoErrors();
        $this->assertSame('PROJECT MANAGER RMTU', $irfan->position->fresh()->name);
    }

    public function test_ubah_penempatan_moves_the_employee_and_their_work_location(): void
    {
        $rina = Employee::where('name', 'Rina Marlina')->firstOrFail(); // OFFICER EMPLOYEE ADMINISTRATION at HO
        $this->put(self::BASE."/{$rina->id}/placement", [...$rina->placement(), 'work_location_id' => $this->location('Site Rantau')])
            ->assertSessionHasNoErrors();

        $rina->refresh();
        $this->assertSame([$this->location('Site Rantau'), null], [$rina->work_location_id, $rina->position_id], 'not in the Site Rantau plan: she waits there');
        $this->assertTrue(Position::whereKey($this->seededPosition('STF-HRG')->id)->exists(), 'the old position stays, now vacant');

        // An unchanged placement keeps the position.
        $siti = Employee::where('name', 'Siti Rahmawati')->firstOrFail();
        $this->put(self::BASE."/{$siti->id}/placement", $siti->placement())->assertSessionHasNoErrors();
        $this->assertSame($this->seededPosition('KDP-HRG')->id, $siti->fresh()->position_id);
    }

    public function test_general_managers_and_bod_positions(): void
    {
        $this->plan('Site Rantau', ['job_level_id' => $this->sub('General Manager'), 'division_id' => Division::where('name', 'UMUM & KEUANGAN')->value('id')]);
        $gm = $this->add('Lukas', ['work_location_id' => $this->location('Site Rantau'), 'job_level_id' => $this->sub('General Manager'), 'division_id' => Division::where('name', 'UMUM & KEUANGAN')->value('id')]);
        $this->assertSame('GENERAL MANAGER UMUM & KEUANGAN', $gm->position->name);

        $this->plan('Head Office', ['job_level_id' => $this->sub('BOD'), 'directorate_id' => Directorate::value('id')]);
        $directorate = $this->add('Mira', ['work_location_id' => $this->location('Head Office'), 'job_level_id' => $this->sub('BOD'), 'directorate_id' => Directorate::value('id')]);
        $this->assertSame(['Direktur Utama', 'directorate'], [$directorate->position->name, $directorate->position->unitLevel()], 'a BOD position carries the BOD name');
    }

    public function test_nama_jabatan_list_can_be_pasted_and_renames_follow_through(): void
    {
        $this->post('/core-hr/settings/options/position_discipline/bulk', ['values_bulk' => "PRODUCTION\r\n  hauling  \nMECHANIC\n\nPRODUCTION"])
            ->assertSessionHasNoErrors();
        $this->assertSame(['PRODUCTION', 'hauling'], LookupValue::where('category', 'position_discipline')->where('id', '>', LookupValue::where('value', 'PRODUCTION OPERATOR')->value('id'))->pluck('value')->all());

        $option = LookupValue::where(['category' => 'position_discipline', 'value' => 'PRODUCTION OPERATOR'])->firstOrFail();
        $this->put("/core-hr/settings/options/{$option->id}", ['value' => 'OPERATOR PRODUKSI'])->assertSessionHasNoErrors();
        $this->assertSame('OPERATOR PRODUKSI', $this->seededPositionName('OPR-PRD'));
        $this->assertSame('OPERATOR PRODUKSI', Employee::where('position_id', $this->seededPosition('OPR-PRD')->id)->value('discipline'), "its holders' placement too");
        $this->delete("/core-hr/settings/options/{$option->id}")->assertSessionHasErrors('value');
    }
}
