<?php

namespace Tests\Feature\CoreHr;

use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Models\EmployeeCategory;
use App\Models\Employee;
use App\Models\PointOfHire;
use App\Models\LookupValue;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use App\Support\Organization\PositionComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/core-hr/settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function named(string $name): Employee
    {
        return Employee::where('name', $name)->with('position', 'profile')->firstOrFail();
    }

    private function positionId(string $code): int
    {
        return $this->seededPosition($code)->id;
    }

    private function option(string $category, string $nilai): LookupValue
    {
        return LookupValue::where(['category' => $category, 'value' => $nilai])->firstOrFail();
    }

    // --- NRP ---------------------------------------------------------------------------------

    public function test_nrp_pattern_and_the_values_read_from_it(): void
    {
        $this->assertNull(EmployeeNumber::error('11524101'));
        $this->assertStringContainsString('8 karakter', EmployeeNumber::error('1152410'));
        $this->assertStringContainsString('Karakter ke-1', EmployeeNumber::error('51524101'));
        $this->assertStringContainsString('(1, 7, 8, 9)', EmployeeNumber::error('51524101'));
        $this->assertStringContainsString('Karakter ke-2–3', EmployeeNumber::error('12224101'));

        $this->assertSame(
            ['employee_category' => 'Tenaga Ahli', 'hire_location' => 'Head Office', 'origin_status' => 'Kiriman'],
            EmployeeNumber::derived($this->named('Agus Setiawan')),
        );
        $this->assertSame('Lokal', EmployeeNumber::derived($this->named('Hendra Wijaya'))['origin_status']);
    }

    public function test_next_nrp_continues_the_numbering_per_prefix(): void
    {
        $hendra = $this->named('Hendra Wijaya'); // 1 15 01 101
        $this->assertSame(substr($hendra->employee_number, 0, 5).'102', EmployeeNumber::next('1', '15', 2001));
        $this->assertSame('71826001', EmployeeNumber::next('7', '18', 2026), 'a fresh prefix starts at 001');
    }

    public function test_a_company_format_with_letters(): void
    {
        // "TY" + jenis karyawan (2 letters) + tahun masuk (4) + nomor urut (4).
        $this->put(self::BASE.'/employee-number-format', ['mode' => 'pattern', 'segments' => [
            ['type' => 'text', 'value' => 'ty'], ['type' => 'category', 'length' => 2], ['type' => 'year', 'length' => 4], ['type' => 'sequence', 'length' => 4],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('"TY" + jenis karyawan (2) + tahun masuk (4) + nomor urut (4)', EmployeeNumber::describe());

        // Codes now take two characters, letters included; existing ones are flagged.
        $this->post(self::BASE.'/employee-categories', ['code' => 'pj', 'name' => 'Penjahit'])->assertSessionHasNoErrors();
        $this->post(self::BASE.'/employee-categories', ['code' => '5', 'name' => 'Satu digit'])->assertSessionHasErrors('code');
        $this->get(self::BASE)->assertInertia(fn (Assert $page) => $page
            ->where('employee_number_format.mismatch.category', ['1', '7', '8', '9'])
            ->where('employee_number_format.mismatch.employees', Employee::withoutGlobalScope('access')->count()));

        $this->assertNull(EmployeeNumber::error('typj20260001'));
        $this->assertSame('TYPJ20260001', EmployeeNumber::normalize(' typj20260001 '));
        $this->assertStringContainsString('harus "TY"', EmployeeNumber::error('XXPJ20260001'));
        $this->assertStringContainsString('bukan kode jenis karyawan', EmployeeNumber::error('TYQQ20260001'));
        $this->assertSame('TYPJ20260001', EmployeeNumber::next('PJ', null, 2026));
        $this->assertSame(['prefix' => 'TYPJ2026', 'category' => 'PJ', 'year' => '2026', 'sequence' => '0001'], EmployeeNumber::parse('TYPJ20260001'));
        // Old NRPs are kept, nothing is read from them any more.
        $this->assertSame(['employee_category' => null, 'hire_location' => null, 'origin_status' => null], EmployeeNumber::derived($this->named('Agus Setiawan')));
    }

    public function test_an_impossible_format_is_refused_and_a_free_one_only_needs_unique_numbers(): void
    {
        $put = fn (array $segments) => $this->put(self::BASE.'/employee-number-format', ['mode' => 'pattern', 'segments' => $segments]);
        $put([['type' => 'sequence', 'length' => 4], ['type' => 'text', 'value' => 'TY']])->assertSessionHasErrors('segments');
        $put([['type' => 'year', 'length' => 3], ['type' => 'sequence', 'length' => 4]])->assertSessionHasErrors('segments');
        $put([['type' => 'text', 'value' => 'ABCDEFGHIJ'], ['type' => 'text', 'value' => 'ABCDEFGHIJ'], ['type' => 'sequence', 'length' => 4]])->assertSessionHasErrors('segments');

        $this->put(self::BASE.'/employee-number-format', ['mode' => 'free', 'max_length' => 10])->assertSessionHasNoErrors();
        $this->assertNull(EmployeeNumber::error('EMP-0001'));
        $this->assertNotNull(EmployeeNumber::error('EMP 0001'));
        $this->assertNotNull(EmployeeNumber::error('EMPLOYEE-00001'));
        $this->assertNull(EmployeeNumber::next('1', '15', 2026));
        $this->post(self::BASE.'/employee-categories', ['code' => 'ABC', 'name' => 'Bebas'])->assertSessionHasNoErrors();
    }

    public function test_year_digits_that_disagree_with_tanggal_masuk_only_warn(): void
    {
        $maya = $this->named('Maya Sari');
        $this->assertNull(EmployeeNumber::yearWarning($maya->employee_number, $maya->profile->hire_date));

        $this->put("/core-hr/employees/{$maya->id}/profile", ['hire_date' => '2019-01-02'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('warnings');
        $this->assertSame('2019-01-02', $maya->profile->fresh()->hire_date->toDateString());
    }

    public function test_adding_an_employee_rejects_an_nrp_outside_the_pattern(): void
    {
        $position = $this->positionId('OPR-PRD');

        $this->post('/core-hr/employees', ['employee_number' => '51526001', 'name' => 'Salah Jenis', 'position_id' => $position, 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasErrors('employee_number');
        $this->post('/core-hr/employees', ['employee_number' => '12026001', 'name' => 'Salah Lokasi', 'position_id' => $position, 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasErrors('employee_number');
        $this->assertStringContainsString('Pengaturan Core HR', session('errors')->first('employee_number'));

        $this->post('/core-hr/employees', ['employee_number' => '81526001', 'name' => 'Mekanik Magang Baru', 'position_id' => $this->positionId('MKN-MTC'), 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('employees', ['employee_number' => '81526001']);
    }

    public function test_import_rejects_new_nrps_outside_the_pattern_and_unknown_choices(): void
    {
        $required = fn (string $employee_number, array $extra = []) => [
            'No. Urut' => 1, 'NRP' => $employee_number, 'Nama' => "Import {$employee_number}", 'NRP SAP' => "5{$employee_number}", 'Work Location' => 'site murung raya',
            'SubGroup' => 'operator', 'Section' => 'operasi tambang', 'Nama Jabatan (Bidang)' => 'production operator',
            'Klasifikasi' => 'non staff', 'Klasifikasi Teknis' => 'Staff/Operator',
            'Tempat Lahir' => 'Puruk Cahu', 'Tanggal Lahir' => '2000-01-01', 'Tanggal Masuk' => '2026-01-05',
            ...$extra,
        ];

        $response = $this->post('/core-hr/employees/excel/import', ['file' => $this->xlsx([
            $required('41526001'),
            $required('91526002', ['Agama' => 'Jedi']),
            $required('91526003', ['Agama' => 'islam', 'Ukuran Baju' => 'xl']),
        ])], ['Accept' => 'application/json'])->assertOk();

        $reasons = collect($response->json('skipped'))->pluck('reason', 'row');
        $this->assertStringContainsString('Karakter ke-1', $reasons[2]);
        $this->assertStringContainsString('"Jedi", tidak ada di daftar pilihan', $reasons[3]);
        $this->assertSame(1, $response->json('created'));

        // Matched case-insensitively and stored as the master list spells it.
        $profile = Employee::where('employee_number', '91526003')->firstOrFail()->profile;
        $this->assertSame(['Islam', 'XL', 'Non Staff'], [$profile->religion, $profile->shirt_size, $profile->employee_class]);
    }

    // --- NRP code masters ---------------------------------------------------------------------

    public function test_remapping_a_lokasi_penerimaan_changes_status_asal(): void
    {
        $lokasi = PointOfHire::where('code', '15')->firstOrFail();

        $this->put(self::BASE."/points-of-hire/{$lokasi->id}", [
            'code' => '15', 'name' => 'Murung Raya', 'work_location_id' => WorkLocation::where('name', 'Head Office')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Kiriman', EmployeeNumber::derived($this->named('Hendra Wijaya'))['origin_status']);
        $this->assertSame('Lokal', EmployeeNumber::derived($this->named('Indah Permatasari'))['origin_status']);

        $this->put(self::BASE."/points-of-hire/{$lokasi->id}", ['code' => '15', 'name' => 'Murung Raya', 'work_location_id' => null]);
        $this->assertNull(EmployeeNumber::derived($this->named('Hendra Wijaya'))['origin_status'], 'unmapped: unknown');
    }

    public function test_codes_used_in_nrps_cannot_be_changed_or_removed(): void
    {
        $normal = EmployeeCategory::where('code', '1')->firstOrFail();
        $this->put(self::BASE."/employee-categories/{$normal->id}", ['code' => '2', 'name' => 'Normal'])->assertSessionHasErrors('code');
        $this->put(self::BASE."/employee-categories/{$normal->id}", ['code' => '1', 'name' => 'Karyawan Reguler'])->assertSessionHasNoErrors();
        $this->delete(self::BASE."/employee-categories/{$normal->id}")->assertSessionHasErrors('code');
        $this->assertSame('Karyawan Reguler', $normal->fresh()->name);

        $mekanikMagang = EmployeeCategory::where('code', '8')->firstOrFail(); // unused in the seed
        $this->delete(self::BASE."/employee-categories/{$mekanikMagang->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($mekanikMagang);

        $this->post(self::BASE.'/points-of-hire', ['code' => '3', 'name' => 'x'])->assertSessionHasErrors('code');
        $this->post(self::BASE.'/points-of-hire', ['code' => '15', 'name' => 'x'])->assertSessionHasErrors('code');
        $this->post(self::BASE.'/points-of-hire', ['code' => '21', 'name' => 'Kuala Kurun'])->assertSessionHasNoErrors();
        $this->assertNull(EmployeeNumber::error('12126001'), 'the new code is accepted in NRPs straight away');

        $rantau = PointOfHire::where('code', '03')->firstOrFail();
        $this->delete(self::BASE."/points-of-hire/{$rantau->id}")->assertSessionHasErrors('code');
    }

    // --- choice lists ----------------------------------------------------------------------------

    public function test_renaming_an_option_follows_through_to_employee_data(): void
    {
        $option = $this->option('technical_class', 'Mekanik/Nonom');
        $holders = Employee::whereHas('profile', fn ($q) => $q->where('technical_class', 'Mekanik/Nonom'))->count();
        $this->assertGreaterThan(0, $holders);

        $this->put(self::BASE."/options/{$option->id}", ['value' => 'Mekanik'])->assertSessionHasNoErrors();

        $this->assertSame($holders, Employee::whereHas('profile', fn ($q) => $q->where('technical_class', 'Mekanik'))->count());
        $this->assertContains('Mekanik', LookupValues::values('technical_class'));
        $this->assertNotContains('Mekanik/Nonom', LookupValues::values('technical_class'));
    }

    public function test_locked_and_used_options_are_protected(): void
    {
        $married = $this->option('marital_status', 'Menikah');
        $this->put(self::BASE."/options/{$married->id}", ['value' => 'Kawin'])->assertSessionHasErrors('value');
        $this->delete(self::BASE."/options/{$married->id}")->assertSessionHasErrors('value');

        $islam = $this->option('religion', 'Islam');
        $this->delete(self::BASE."/options/{$islam->id}")->assertSessionHasErrors('value');
        $this->assertStringContainsString('masih dipakai', session('errors')->first('value'));

        $unused = $this->option('shirt_size', 'XXXL');
        $this->delete(self::BASE."/options/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);

        $this->post(self::BASE.'/options/religion', ['value' => 'islam'])->assertSessionHasNoErrors(); // different spelling is a different value
        $this->post(self::BASE.'/options/religion', ['value' => 'Islam'])->assertSessionHasErrors('value');
        $this->post(self::BASE.'/options/unknown_category', ['value' => 'x'])->assertNotFound();
    }

    public function test_new_options_are_usable_right_away_and_can_be_reordered(): void
    {
        $this->post(self::BASE.'/options/termination_reason', ['value' => 'Habis Kontrak Magang'])->assertSessionHasNoErrors();
        $lina = $this->named('Lina Agustina');
        $this->post("/core-hr/employees/{$lina->id}/termination", ['reason' => 'Habis Kontrak Magang', 'date' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        $this->post(self::BASE.'/options/shoe_size', ['value' => '47'])->assertSessionHasNoErrors();
        $maya = $this->named('Maya Sari');
        $this->put("/core-hr/employees/{$maya->id}/profile", ['shoe_size' => '47'])->assertSessionHasNoErrors();
        $this->put("/core-hr/employees/{$maya->id}/profile", ['shoe_size' => '48'])->assertSessionHasErrors('shoe_size');

        $pertama = LookupValues::values('religion')[0];
        $this->post(self::BASE."/options/{$this->option('religion', $pertama)->id}/move", ['direction' => 'down'])->assertSessionHasNoErrors();
        $this->assertSame($pertama, LookupValues::values('religion')[1]);
    }

    // --- organisation ------------------------------------------------------------------------------

    public function test_organisation_entries_in_use_cannot_be_deleted(): void
    {
        $operasional = Division::where('name', 'OPERASIONAL')->firstOrFail();
        $this->delete(self::BASE."/division/{$operasional->id}")->assertSessionHasErrors('name');

        // Renaming a unit renames the positions named after it.
        $finance = Department::where('name', 'FINANCE')->firstOrFail();
        $this->put(self::BASE."/department/{$finance->id}", ['name' => 'FINANCE & ACCOUNTING', 'division_id' => $finance->division_id])
            ->assertSessionHasNoErrors();
        $this->assertSame('FINANCE & ACCOUNTING', $finance->fresh()->name);
        $this->assertSame('MANAGER FINANCE & ACCOUNTING', $this->seededPositionName('KDP-FIN'));
        $this->delete(self::BASE."/department/{$finance->id}")->assertSessionHasErrors('name');

        $this->delete(self::BASE."/position/{$this->positionId('OPR-PRD')}")->assertSessionHasErrors('name');
        $this->delete(self::BASE.'/work-locations/'.WorkLocation::where('name', 'Site Rantau')->value('id'))->assertSessionHasErrors('name');

        $this->post(self::BASE.'/department', ['name' => 'Safety', 'division_id' => $operasional->id])->assertSessionHasNoErrors();
        $safety = Department::where('name', 'Safety')->firstOrFail();
        $this->delete(self::BASE."/department/{$safety->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($safety);
    }

    public function test_jabatan_rules(): void
    {
        $placement = fn (string $jobLevel, string $location, array $unit) => [
            'job_level_id' => JobLevel::where('name', $jobLevel)->value('id'),
            'work_location_id' => WorkLocation::where('name', $location)->value('id'),
            ...$unit,
        ];
        $workshop = ['section_id' => Section::where('name', 'WORKSHOP')->value('id')];

        // A vacancy is composed like any placement: Section Head + Section WORKSHOP.
        $this->post(self::BASE.'/position', $placement('Section Head HO', 'Head Office', $workshop))->assertSessionHasNoErrors();
        $ho = Position::where('name', 'SECTION HEAD WORKSHOP')->where('work_location_id', WorkLocation::where('name', 'Head Office')->value('id'))->firstOrFail();
        $this->assertSame('section', $ho->unitLevel());

        // The same title at another place is another position; at the same place it exists already.
        $this->assertSame(2, Position::where('name', 'SECTION HEAD WORKSHOP')->count());
        $this->post(self::BASE.'/position', $placement('Section Head Site', 'Site Rantau', $workshop))->assertSessionHasErrors('placement');
        $this->assertStringContainsString('sudah ada', session('errors')->first('placement'));

        // What a SubGroup needs must be given.
        $this->post(self::BASE.'/position', $placement('Section Head HO', 'Head Office', []))->assertSessionHasErrors(['placement' => 'Pilih Section.']);
        $this->post(self::BASE.'/position', $placement('Operator', 'Head Office', $workshop))->assertSessionHasErrors(['placement' => 'Pilih Nama Jabatan.']);

        // Positions are not renamed by hand; an empty one can be removed.
        $this->put(self::BASE."/position/{$ho->id}", ['name' => 'X'])->assertMethodNotAllowed();
        $this->delete(self::BASE."/position/{$ho->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($ho);
    }

    public function test_sub_groups_carry_a_layer_and_the_naming_rule(): void
    {
        $this->post(self::BASE.'/job-levels', ['name' => 'Officer', 'layer' => 7, 'source' => 'discipline'])->assertSessionHasErrors('layer');
        $this->post(self::BASE.'/job-levels', ['name' => 'Manager', 'layer' => 4, 'source' => 'department'])->assertSessionHasErrors('name');
        // Superiors are arranged in the chart, not per SubGroup.
        $this->post(self::BASE.'/job-levels', ['name' => 'Site Supervisor', 'layer' => 3, 'source' => 'work_location', 'title_prefix' => 'SITE SUPERVISOR'])->assertSessionHasNoErrors();
        $deputy = JobLevel::where('name', 'Deputy Project Manager')->firstOrFail();
        $this->put(self::BASE."/job-levels/{$deputy->id}", ['name' => $deputy->name, 'layer' => 4, 'source' => 'work_location', 'title_prefix' => 'DEPUTY PM'])->assertSessionHasNoErrors();
        $this->assertSame('DEPUTY PM', $deputy->fresh()->title_prefix);
        $this->post(self::BASE.'/job-levels', ['name' => 'Supervisor', 'layer' => 2, 'source' => 'discipline', 'title_prefix' => ' supervisor '])->assertSessionHasNoErrors();
        $this->assertSame('SUPERVISOR', JobLevel::where('name', 'Supervisor')->value('title_prefix'), 'titles are stored in capitals');

        $manager = JobLevel::where('name', 'Manager')->firstOrFail();
        $this->delete(self::BASE."/job-levels/{$manager->id}")->assertSessionHasErrors('name');

        // Data Lengkap: SubGroup and the composed title.
        $response = $this->get('/core-hr/employees/full-data');
        $keys = array_column($response->inertiaProps('groups.0.columns'), 'key');
        $this->assertContains('job_level', $keys);
        $this->assertNotContains('status', $keys);
        $rows = collect($response->inertiaProps('rows'))->keyBy('name');
        $this->assertSame(['Manager', 'MANAGER HRGA', 'HRGA', null], [$rows['Siti Rahmawati']['job_level'], $rows['Siti Rahmawati']['position_name'], $rows['Siti Rahmawati']['department_name'], $rows['Siti Rahmawati']['section_name']]);
        $this->assertSame(['Operator', 'PRODUCTION OPERATOR', 'OPERASI TAMBANG'], [$rows['Yoga Pratama']['job_level'], $rows['Yoga Pratama']['position_name'], $rows['Yoga Pratama']['section_name']]);

        // Changing the rule renames the positions.
        $this->put(self::BASE."/job-levels/{$manager->id}", ['name' => 'Department Manager', 'layer' => 4, 'source' => 'department', 'title_prefix' => 'DEPARTMENT MANAGER'])->assertSessionHasNoErrors();
        $rows = collect($this->get('/core-hr/employees/full-data')->inertiaProps('rows'))->keyBy('name');
        $this->assertSame(['Department Manager', 'DEPARTMENT MANAGER HRGA'], [$rows['Siti Rahmawati']['job_level'], $rows['Siti Rahmawati']['position_name']]);
    }

    public function test_work_location_comes_with_the_placement_and_decides_status_asal(): void
    {
        $hendra = $this->named('Hendra Wijaya'); // hired at Murung Raya (15), GENERAL MANAGER OPERASIONAL at Site Murung Raya
        $this->assertSame('Lokal', EmployeeNumber::derived($hendra)['origin_status']);

        // The location is part of the placement ("Ubah Penempatan"), not a free profile field.
        // Head Office has no GENERAL MANAGER OPERASIONAL in its plan: he waits to be placed there.
        $this->put("/core-hr/employees/{$hendra->id}/placement", [
            ...PositionComposer::placementOf($hendra->position),
            'work_location_id' => WorkLocation::where('name', 'Head Office')->value('id'),
        ])->assertSessionHasNoErrors();
        $hendra->refresh();
        $this->assertSame(['Head Office', null], [$hendra->workLocation->name, $hendra->position_id]);
        $this->assertSame('Kiriman', EmployeeNumber::derived($hendra)['origin_status']);
        $this->put("/core-hr/employees/{$hendra->id}/profile", ['work_location' => 'Site Rantau'])->assertSessionHasNoErrors();
        $this->assertSame('Head Office', $hendra->fresh()->workLocation->name, 'the profile form does not move anyone');

        // A location in use cannot be removed.
        $this->delete(self::BASE.'/work-locations/'.WorkLocation::where('name', 'Head Office')->value('id'))->assertSessionHasErrors('name');

        // Excel: a new Work Location keeps the rest of the placement.
        $response = $this->post('/core-hr/employees/excel/import', ['file' => $this->xlsx([
            ['NRP' => $hendra->employee_number, 'Work Location' => 'RTU'],
            ['NRP' => $this->named('Yoga Pratama')->employee_number, 'Work Location' => 'Pluto'],
        ])], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('Site Rantau', $hendra->fresh()->workLocation->name, 'found by its code');
        $this->assertSame('General Manager', $hendra->fresh()->jobLevel->name, 'the rest of the placement is kept');
        $this->assertStringContainsString('belum ada di Plan Struktur Organisasi', implode(' ', $response->json('rows.0.warnings')));
        $this->assertStringContainsString('Work Location "Pluto" tidak ada', $response->json('skipped.0.reason'));
    }

    // --- page & roles ------------------------------------------------------------------------------

    public function test_page_lists_every_master_with_usage(): void
    {
        $this->get(self::BASE)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CoreHr/Settings/Index')
                ->has('type', 4)
                ->where('type.0.code', '1')
                ->where('type.0.employee_count', 13)
                ->has('location', 4)
                ->has('work_locations', 3)
                ->where('work_locations.0.employee_count', 5)
                ->has('job_levels', 15)
                ->where('job_levels.0.layer', 6)
                ->where('job_levels.0.source', 'directorate')
                ->has('positions', 11)
                ->has('placement_options.discipline', 5)
                ->has('options', count(LookupValues::CATEGORIES))
                ->where('options.0.category', 'employee_class'));
    }

    public function test_viewers_cannot_open_or_change_master_data(): void
    {
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());

        $this->get(self::BASE)->assertForbidden();
        $this->post(self::BASE.'/employee-categories', ['code' => '5', 'name' => 'x'])->assertForbidden();
        $this->post(self::BASE.'/options/religion', ['value' => 'x'])->assertForbidden();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $headers = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Data Karyawan');
        foreach ([$headers, ...array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? null, $headers), $rows)] as $r => $values) {
            foreach ($values as $c => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1).($r + 1), $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'md').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }
}
