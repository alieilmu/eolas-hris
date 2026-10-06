<?php

namespace Tests\Feature\Manpower;

use App\Models\Bod;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\MasterOption;
use App\Models\Section;
use App\Models\SubGroup;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Positions composed from where an employee is placed: Work Location → SubGroup → unit / bidang. */
class PenempatanTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/manpower/karyawan';

    private int $nrp = 100;

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
        return SubGroup::where('nama', $nama)->value('id');
    }

    /** Adds an employee with a placement; returns the created employee (or null when refused). */
    private function add(string $name, array $placement): ?Karyawan
    {
        $this->post(self::BASE, ['nrp' => '10126'.$this->nrp++, 'name' => $name, ...$placement]);

        return Karyawan::where('name', $name)->with('jabatan.workLocation')->first();
    }

    /** Adds positions to a work location's plan. */
    private function plan(string $location, array ...$placements): void
    {
        $ops = array_map(fn ($pl, $i) => ['type' => 'create', 'temp_id' => "temp-{$i}", 'parent' => null, 'penempatan' => ['work_location_id' => $this->location($location), ...$pl]], $placements, array_keys($placements));
        $this->post('/manpower/org-structure/plan/'.$this->location($location).'/ops', ['ops' => $ops])->assertSessionHasNoErrors();
    }

    public function test_an_employee_fills_the_matching_position_of_the_plan_or_waits(): void
    {
        $personalia = Section::where('name', 'PERSONALIA')->value('id');
        $officer = $this->sub('Officer HO/ Group Leader');
        $before = Jabatan::count();

        // OFFICER EMPLOYEE ADMINISTRATION is in the Head Office plan.
        $andi = $this->add('Andi', ['work_location_id' => $this->location('Head Office'), 'sub_group_id' => $officer, 'section_id' => $personalia, 'bidang' => 'employee administration']);
        $this->assertSame($this->seededJabatan('STF-HRG')->id, $andi->jabatan_id);
        $this->assertSame(['EMPLOYEE ADMINISTRATION', 'HRGA'], [$andi->bidang, $andi->departemen->name], 'stored as the master list spells it, with the departemen of the section');

        // The same title at a site is GROUP LEADER EMPLOYEE ADMINISTRATION, not in the Site Rantau plan: Budi waits.
        $budi = $this->add('Budi', ['work_location_id' => $this->location('Site Rantau'), 'sub_group_id' => $officer, 'section_id' => $personalia, 'bidang' => 'EMPLOYEE ADMINISTRATION']);
        $this->assertNull($budi->jabatan_id);
        $this->assertSame($before, Jabatan::count(), 'placing people never creates positions');

        // Once it is in the plan, the same placement fills it.
        $this->plan('Site Rantau', ['sub_group_id' => $officer, 'section_id' => $personalia, 'bidang' => 'EMPLOYEE ADMINISTRATION']);
        $this->put(self::BASE."/{$budi->id}/penempatan", $budi->placement())->assertSessionHasNoErrors();
        $this->assertSame('GROUP LEADER EMPLOYEE ADMINISTRATION', $budi->fresh()->jabatan->name);

        // Without a Section: the only position with that title at the place.
        $citra = $this->add('Citra', ['work_location_id' => $this->location('Head Office'), 'sub_group_id' => $officer, 'bidang' => 'EMPLOYEE ADMINISTRATION']);
        $this->assertSame($this->seededJabatan('STF-HRG')->id, $citra->jabatan_id);

        // A position for one person that is held: the newcomer waits.
        $dodi = $this->add('Dodi', ['work_location_id' => $this->location('Head Office'), 'sub_group_id' => $this->sub('Manager'), 'departemen_id' => Departemen::where('name', 'HRGA')->value('id')]);
        $this->assertNull($dodi->jabatan_id);
        $this->assertSame('Manager', $dodi->subGroup->nama);

        // What the SubGroup needs must be given.
        $this->post(self::BASE, ['nrp' => '10126199', 'name' => 'Fajar', 'work_location_id' => $this->location('Head Office'), 'sub_group_id' => $this->sub('Section Head HO')])
            ->assertSessionHasErrors(['penempatan' => 'Pilih Section.']);
        $this->post(self::BASE, ['nrp' => '10126199', 'name' => 'Fajar', 'work_location_id' => $this->location('Head Office'), 'sub_group_id' => $officer, 'section_id' => $personalia, 'bidang' => 'ASTRONAUT'])
            ->assertSessionHasErrors('penempatan');
        $this->assertStringContainsString('tidak ada di master Nama Jabatan', session('errors')->first('penempatan'));
    }

    public function test_section_heads_at_ho_and_at_a_site_are_separate_positions(): void
    {
        $workshop = ['section_id' => Section::where('name', 'WORKSHOP')->value('id')];

        // The site's Section Head WORKSHOP is Joko; one at Head Office is another position.
        $this->plan('Head Office', ['sub_group_id' => $this->sub('Section Head HO'), ...$workshop]);
        $ho = $this->add('Gita', ['work_location_id' => $this->location('Head Office'), 'sub_group_id' => $this->sub('Section Head HO'), ...$workshop]);
        $this->assertSame('SECTION HEAD WORKSHOP', $ho->jabatan->name);
        $this->assertNotSame($this->seededJabatan('SPV-MTC')->id, $ho->jabatan_id);

        // Layer 3 and up: one active holder per position; a second one waits.
        $hadi = $this->add('Hadi', ['work_location_id' => $this->location('Site Rantau'), 'sub_group_id' => $this->sub('Section Head Site'), ...$workshop]);
        $this->assertNull($hadi->jabatan_id);
    }

    public function test_project_managers_are_named_after_their_site(): void
    {
        $site = $this->location('Site Murung Raya');
        $this->post("/manpower/org-structure/plan/{$site}/setup", ['deputy' => true, 'atasan_jabatan_id' => $this->seededJabatan('KDP-HRG')->id])->assertSessionHasNoErrors();

        $irfan = $this->add('Irfan', ['work_location_id' => $site, 'sub_group_id' => $this->sub('Project Manager')]);
        $this->assertSame(['PROJECT MANAGER MRY', 'MANAGER HRGA'], [$irfan->jabatan->name, $irfan->jabatan->atasan()?->name], 'named with the work location code');
        $joni = $this->add('Joni', ['work_location_id' => $site, 'sub_group_id' => $this->sub('Deputy Project Manager')]);
        $this->assertSame(['DEPUTY PROJECT MANAGER MRY', 'PROJECT MANAGER MRY'], [$joni->jabatan->name, $joni->jabatan->atasan()?->name]);

        // Never under a lower layer.
        $this->post("/manpower/org-structure/plan/{$site}/ops", ['ops' => [['type' => 'reparent', 'jabatan' => $irfan->jabatan_id, 'parent' => $this->seededJabatan('STF-HRG')->id]]])
            ->assertSessionHasErrors('ops');
        $this->assertStringContainsString('layer atasan tidak boleh di bawah bawahannya', session('errors')->first('ops'));

        // A new code renames the positions named after the place.
        $this->put('/manpower/master-data/work-location/'.$site, ['name' => 'Site Murung Raya', 'kode' => 'RMTU', 'type' => 'Site'])->assertSessionHasNoErrors();
        $this->assertSame('PROJECT MANAGER RMTU', $irfan->jabatan->fresh()->name);
    }

    public function test_ubah_penempatan_moves_the_employee_and_their_work_location(): void
    {
        $rina = Karyawan::where('name', 'Rina Marlina')->firstOrFail(); // OFFICER EMPLOYEE ADMINISTRATION at HO
        $this->put(self::BASE."/{$rina->id}/penempatan", [...$rina->placement(), 'work_location_id' => $this->location('Site Rantau')])
            ->assertSessionHasNoErrors();

        $rina->refresh();
        $this->assertSame([$this->location('Site Rantau'), null], [$rina->work_location_id, $rina->jabatan_id], 'not in the Site Rantau plan: she waits there');
        $this->assertTrue(Jabatan::whereKey($this->seededJabatan('STF-HRG')->id)->exists(), 'the old position stays, now vacant');

        // An unchanged placement keeps the position.
        $siti = Karyawan::where('name', 'Siti Rahmawati')->firstOrFail();
        $this->put(self::BASE."/{$siti->id}/penempatan", $siti->placement())->assertSessionHasNoErrors();
        $this->assertSame($this->seededJabatan('KDP-HRG')->id, $siti->fresh()->jabatan_id);
    }

    public function test_general_managers_and_bod_positions(): void
    {
        $this->plan('Site Rantau', ['sub_group_id' => $this->sub('General Manager'), 'divisi_id' => Divisi::where('name', 'UMUM & KEUANGAN')->value('id')]);
        $gm = $this->add('Lukas', ['work_location_id' => $this->location('Site Rantau'), 'sub_group_id' => $this->sub('General Manager'), 'divisi_id' => Divisi::where('name', 'UMUM & KEUANGAN')->value('id')]);
        $this->assertSame('GENERAL MANAGER UMUM & KEUANGAN', $gm->jabatan->name);

        $this->plan('Head Office', ['sub_group_id' => $this->sub('BOD'), 'bod_id' => Bod::value('id')]);
        $bod = $this->add('Mira', ['work_location_id' => $this->location('Head Office'), 'sub_group_id' => $this->sub('BOD'), 'bod_id' => Bod::value('id')]);
        $this->assertSame(['Direktur Utama', 'bod'], [$bod->jabatan->name, $bod->jabatan->unitLevel()], 'a BOD position carries the BOD name');
    }

    public function test_nama_jabatan_list_can_be_pasted_and_renames_follow_through(): void
    {
        $this->post('/manpower/master-data/opsi/bidang_jabatan/banyak', ['nilai_banyak' => "PRODUCTION\r\n  hauling  \nMECHANIC\n\nPRODUCTION"])
            ->assertSessionHasNoErrors();
        $this->assertSame(['PRODUCTION', 'hauling'], MasterOption::where('kategori', 'bidang_jabatan')->where('id', '>', MasterOption::where('nilai', 'PRODUCTION OPERATOR')->value('id'))->pluck('nilai')->all());

        $option = MasterOption::where(['kategori' => 'bidang_jabatan', 'nilai' => 'PRODUCTION OPERATOR'])->firstOrFail();
        $this->put("/manpower/master-data/opsi/{$option->id}", ['nilai' => 'OPERATOR PRODUKSI'])->assertSessionHasNoErrors();
        $this->assertSame('OPERATOR PRODUKSI', $this->seededJabatanName('OPR-PRD'));
        $this->assertSame('OPERATOR PRODUKSI', Karyawan::where('jabatan_id', $this->seededJabatan('OPR-PRD')->id)->value('bidang'), "its holders' placement too");
        $this->delete("/manpower/master-data/opsi/{$option->id}")->assertSessionHasErrors('nilai');
    }
}
