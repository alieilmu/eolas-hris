<?php

namespace Tests\Feature\Manpower;

use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\JenisKaryawan;
use App\Models\Karyawan;
use App\Models\LokasiPenerimaan;
use App\Models\MasterOption;
use App\Models\Section;
use App\Models\SubGroup;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\Nrp;
use App\Support\Org\JabatanComposer;
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

    private const BASE = '/manpower/master-data';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function named(string $name): Karyawan
    {
        return Karyawan::where('name', $name)->with('jabatan', 'profile')->firstOrFail();
    }

    private function jabatanId(string $kode): int
    {
        return $this->seededJabatan($kode)->id;
    }

    private function option(string $kategori, string $nilai): MasterOption
    {
        return MasterOption::where(['kategori' => $kategori, 'nilai' => $nilai])->firstOrFail();
    }

    // --- NRP ---------------------------------------------------------------------------------

    public function test_nrp_pattern_and_the_values_read_from_it(): void
    {
        $this->assertNull(Nrp::error('11524101'));
        $this->assertStringContainsString('8 digit', Nrp::error('1152410'));
        $this->assertStringContainsString('Digit ke-1', Nrp::error('51524101'));
        $this->assertStringContainsString('(1, 7, 8, 9)', Nrp::error('51524101'));
        $this->assertStringContainsString('Digit ke-2–3', Nrp::error('12224101'));

        $this->assertSame(
            ['jenis_karyawan' => 'Tenaga Ahli', 'tempat_penerimaan' => 'Head Office', 'status_asal' => 'Kiriman'],
            Nrp::derived($this->named('Agus Setiawan')),
        );
        $this->assertSame('Lokal', Nrp::derived($this->named('Hendra Wijaya'))['status_asal']);
    }

    public function test_next_nrp_continues_the_numbering_per_prefix(): void
    {
        $hendra = $this->named('Hendra Wijaya'); // 1 15 01 101
        $this->assertSame(substr($hendra->nrp, 0, 5).'102', Nrp::next('1', '15', 2001));
        $this->assertSame('71826001', Nrp::next('7', '18', 2026), 'a fresh prefix starts at 001');
    }

    public function test_year_digits_that_disagree_with_tanggal_masuk_only_warn(): void
    {
        $maya = $this->named('Maya Sari');
        $this->assertNull(Nrp::yearWarning($maya->nrp, $maya->profile->tanggal_masuk));

        $this->put("/manpower/karyawan/{$maya->id}/profil", ['tanggal_masuk' => '2019-01-02'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('warnings');
        $this->assertSame('2019-01-02', $maya->profile->fresh()->tanggal_masuk->toDateString());
    }

    public function test_adding_an_employee_rejects_an_nrp_outside_the_pattern(): void
    {
        $jabatan = $this->jabatanId('OPR-PRD');

        $this->post('/manpower/karyawan', ['nrp' => '51526001', 'name' => 'Salah Jenis', 'jabatan_id' => $jabatan, 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasErrors('nrp');
        $this->post('/manpower/karyawan', ['nrp' => '12026001', 'name' => 'Salah Lokasi', 'jabatan_id' => $jabatan, 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasErrors('nrp');
        $this->assertStringContainsString('Master Data Personalia', session('errors')->first('nrp'));

        $this->post('/manpower/karyawan', ['nrp' => '81526001', 'name' => 'Mekanik Magang Baru', 'jabatan_id' => $this->jabatanId('MKN-MTC'), 'work_location_id' => WorkLocation::where('name', 'Site Murung Raya')->value('id')])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('karyawans', ['nrp' => '81526001']);
    }

    public function test_import_rejects_new_nrps_outside_the_pattern_and_unknown_choices(): void
    {
        $required = fn (string $nrp, array $extra = []) => [
            'No. Urut' => 1, 'NRP' => $nrp, 'Nama' => "Import {$nrp}", 'NRP SAP' => "5{$nrp}", 'Work Location' => 'site murung raya',
            'SubGroup' => 'operator', 'Section' => 'operasi tambang', 'Nama Jabatan (Bidang)' => 'production operator',
            'Klasifikasi' => 'non staff', 'Klasifikasi Teknis' => 'Staff/Operator',
            'Tempat Lahir' => 'Puruk Cahu', 'Tanggal Lahir' => '2000-01-01', 'Tanggal Masuk' => '2026-01-05',
            ...$extra,
        ];

        $response = $this->post('/manpower/karyawan/excel/import', ['file' => $this->xlsx([
            $required('41526001'),
            $required('91526002', ['Agama' => 'Jedi']),
            $required('91526003', ['Agama' => 'islam', 'Ukuran Baju' => 'xl']),
        ])], ['Accept' => 'application/json'])->assertOk();

        $reasons = collect($response->json('skipped'))->pluck('reason', 'row');
        $this->assertStringContainsString('Digit ke-1', $reasons[2]);
        $this->assertStringContainsString('"Jedi", tidak ada di daftar pilihan', $reasons[3]);
        $this->assertSame(1, $response->json('created'));

        // Matched case-insensitively and stored as the master list spells it.
        $profile = Karyawan::where('nrp', '91526003')->firstOrFail()->profile;
        $this->assertSame(['Islam', 'XL', 'Non Staff'], [$profile->agama, $profile->ukuran_baju, $profile->klasifikasi]);
    }

    // --- NRP code masters ---------------------------------------------------------------------

    public function test_remapping_a_lokasi_penerimaan_changes_status_asal(): void
    {
        $lokasi = LokasiPenerimaan::where('kode', '15')->firstOrFail();

        $this->put(self::BASE."/lokasi-penerimaan/{$lokasi->id}", [
            'kode' => '15', 'nama' => 'Murung Raya', 'work_location_id' => WorkLocation::where('name', 'Head Office')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Kiriman', Nrp::derived($this->named('Hendra Wijaya'))['status_asal']);
        $this->assertSame('Lokal', Nrp::derived($this->named('Indah Permatasari'))['status_asal']);

        $this->put(self::BASE."/lokasi-penerimaan/{$lokasi->id}", ['kode' => '15', 'nama' => 'Murung Raya', 'work_location_id' => null]);
        $this->assertNull(Nrp::derived($this->named('Hendra Wijaya'))['status_asal'], 'unmapped: unknown');
    }

    public function test_codes_used_in_nrps_cannot_be_changed_or_removed(): void
    {
        $normal = JenisKaryawan::where('kode', '1')->firstOrFail();
        $this->put(self::BASE."/jenis-karyawan/{$normal->id}", ['kode' => '2', 'nama' => 'Normal'])->assertSessionHasErrors('kode');
        $this->put(self::BASE."/jenis-karyawan/{$normal->id}", ['kode' => '1', 'nama' => 'Karyawan Reguler'])->assertSessionHasNoErrors();
        $this->delete(self::BASE."/jenis-karyawan/{$normal->id}")->assertSessionHasErrors('kode');
        $this->assertSame('Karyawan Reguler', $normal->fresh()->nama);

        $mekanikMagang = JenisKaryawan::where('kode', '8')->firstOrFail(); // unused in the seed
        $this->delete(self::BASE."/jenis-karyawan/{$mekanikMagang->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($mekanikMagang);

        $this->post(self::BASE.'/lokasi-penerimaan', ['kode' => '3', 'nama' => 'x'])->assertSessionHasErrors('kode');
        $this->post(self::BASE.'/lokasi-penerimaan', ['kode' => '15', 'nama' => 'x'])->assertSessionHasErrors('kode');
        $this->post(self::BASE.'/lokasi-penerimaan', ['kode' => '21', 'nama' => 'Kuala Kurun'])->assertSessionHasNoErrors();
        $this->assertNull(Nrp::error('12126001'), 'the new code is accepted in NRPs straight away');

        $rantau = LokasiPenerimaan::where('kode', '03')->firstOrFail();
        $this->delete(self::BASE."/lokasi-penerimaan/{$rantau->id}")->assertSessionHasErrors('kode');
    }

    // --- choice lists ----------------------------------------------------------------------------

    public function test_renaming_an_option_follows_through_to_employee_data(): void
    {
        $option = $this->option('klasifikasi_teknis', 'Mekanik/Nonom');
        $holders = Karyawan::whereHas('profile', fn ($q) => $q->where('klasifikasi_teknis', 'Mekanik/Nonom'))->count();
        $this->assertGreaterThan(0, $holders);

        $this->put(self::BASE."/opsi/{$option->id}", ['nilai' => 'Mekanik'])->assertSessionHasNoErrors();

        $this->assertSame($holders, Karyawan::whereHas('profile', fn ($q) => $q->where('klasifikasi_teknis', 'Mekanik'))->count());
        $this->assertContains('Mekanik', MasterOptions::values('klasifikasi_teknis'));
        $this->assertNotContains('Mekanik/Nonom', MasterOptions::values('klasifikasi_teknis'));
    }

    public function test_locked_and_used_options_are_protected(): void
    {
        $menikah = $this->option('status_perkawinan', 'Menikah');
        $this->put(self::BASE."/opsi/{$menikah->id}", ['nilai' => 'Kawin'])->assertSessionHasErrors('nilai');
        $this->delete(self::BASE."/opsi/{$menikah->id}")->assertSessionHasErrors('nilai');

        $islam = $this->option('agama', 'Islam');
        $this->delete(self::BASE."/opsi/{$islam->id}")->assertSessionHasErrors('nilai');
        $this->assertStringContainsString('masih dipakai', session('errors')->first('nilai'));

        $unused = $this->option('ukuran_baju', 'XXXL');
        $this->delete(self::BASE."/opsi/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);

        $this->post(self::BASE.'/opsi/agama', ['nilai' => 'islam'])->assertSessionHasNoErrors(); // different spelling is a different value
        $this->post(self::BASE.'/opsi/agama', ['nilai' => 'Islam'])->assertSessionHasErrors('nilai');
        $this->post(self::BASE.'/opsi/tidak-ada', ['nilai' => 'x'])->assertNotFound();
    }

    public function test_new_options_are_usable_right_away_and_can_be_reordered(): void
    {
        $this->post(self::BASE.'/opsi/alasan_keluar', ['nilai' => 'Habis Kontrak Magang'])->assertSessionHasNoErrors();
        $lina = $this->named('Lina Agustina');
        $this->post("/manpower/karyawan/{$lina->id}/keluar", ['alasan' => 'Habis Kontrak Magang', 'tanggal' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        $this->post(self::BASE.'/opsi/ukuran_sepatu', ['nilai' => '47'])->assertSessionHasNoErrors();
        $maya = $this->named('Maya Sari');
        $this->put("/manpower/karyawan/{$maya->id}/profil", ['ukuran_sepatu' => '47'])->assertSessionHasNoErrors();
        $this->put("/manpower/karyawan/{$maya->id}/profil", ['ukuran_sepatu' => '48'])->assertSessionHasErrors('ukuran_sepatu');

        $pertama = MasterOptions::values('agama')[0];
        $this->post(self::BASE."/opsi/{$this->option('agama', $pertama)->id}/geser", ['arah' => 'turun'])->assertSessionHasNoErrors();
        $this->assertSame($pertama, MasterOptions::values('agama')[1]);
    }

    // --- organisation ------------------------------------------------------------------------------

    public function test_organisation_entries_in_use_cannot_be_deleted(): void
    {
        $operasional = Divisi::where('name', 'OPERASIONAL')->firstOrFail();
        $this->delete(self::BASE."/divisi/{$operasional->id}")->assertSessionHasErrors('name');

        // Renaming a unit renames the positions named after it.
        $finance = Departemen::where('name', 'FINANCE')->firstOrFail();
        $this->put(self::BASE."/departemen/{$finance->id}", ['name' => 'FINANCE & ACCOUNTING', 'divisi_id' => $finance->divisi_id])
            ->assertSessionHasNoErrors();
        $this->assertSame('FINANCE & ACCOUNTING', $finance->fresh()->name);
        $this->assertSame('MANAGER FINANCE & ACCOUNTING', $this->seededJabatanName('KDP-FIN'));
        $this->delete(self::BASE."/departemen/{$finance->id}")->assertSessionHasErrors('name');

        $this->delete(self::BASE."/jabatan/{$this->jabatanId('OPR-PRD')}")->assertSessionHasErrors('name');
        $this->delete(self::BASE.'/work-location/'.WorkLocation::where('name', 'Site Rantau')->value('id'))->assertSessionHasErrors('name');

        $this->post(self::BASE.'/departemen', ['name' => 'Safety', 'divisi_id' => $operasional->id])->assertSessionHasNoErrors();
        $safety = Departemen::where('name', 'Safety')->firstOrFail();
        $this->delete(self::BASE."/departemen/{$safety->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($safety);
    }

    public function test_jabatan_rules(): void
    {
        $placement = fn (string $subGroup, string $location, array $unit) => [
            'sub_group_id' => SubGroup::where('nama', $subGroup)->value('id'),
            'work_location_id' => WorkLocation::where('name', $location)->value('id'),
            ...$unit,
        ];
        $workshop = ['section_id' => Section::where('name', 'WORKSHOP')->value('id')];

        // A vacancy is composed like any placement: Section Head + Section WORKSHOP.
        $this->post(self::BASE.'/jabatan', $placement('Section Head HO', 'Head Office', $workshop))->assertSessionHasNoErrors();
        $ho = Jabatan::where('name', 'SECTION HEAD WORKSHOP')->where('work_location_id', WorkLocation::where('name', 'Head Office')->value('id'))->firstOrFail();
        $this->assertSame('section', $ho->unitLevel());

        // The same title at another place is another position; at the same place it exists already.
        $this->assertSame(2, Jabatan::where('name', 'SECTION HEAD WORKSHOP')->count());
        $this->post(self::BASE.'/jabatan', $placement('Section Head Site', 'Site Rantau', $workshop))->assertSessionHasErrors('penempatan');
        $this->assertStringContainsString('sudah ada', session('errors')->first('penempatan'));

        // What a SubGroup needs must be given.
        $this->post(self::BASE.'/jabatan', $placement('Section Head HO', 'Head Office', []))->assertSessionHasErrors(['penempatan' => 'Pilih Section.']);
        $this->post(self::BASE.'/jabatan', $placement('Operator', 'Head Office', $workshop))->assertSessionHasErrors(['penempatan' => 'Pilih Nama Jabatan.']);

        // Positions are not renamed by hand; an empty one can be removed.
        $this->put(self::BASE."/jabatan/{$ho->id}", ['name' => 'X'])->assertMethodNotAllowed();
        $this->delete(self::BASE."/jabatan/{$ho->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($ho);
    }

    public function test_sub_groups_carry_a_layer_and_the_naming_rule(): void
    {
        $this->post(self::BASE.'/sub-group', ['nama' => 'Officer', 'layer' => 7, 'sumber' => 'bidang'])->assertSessionHasErrors('layer');
        $this->post(self::BASE.'/sub-group', ['nama' => 'Manager', 'layer' => 4, 'sumber' => 'departemen'])->assertSessionHasErrors('nama');
        // Superiors are arranged in the chart, not per SubGroup.
        $this->post(self::BASE.'/sub-group', ['nama' => 'Site Supervisor', 'layer' => 3, 'sumber' => 'work_location', 'gelar' => 'SITE SUPERVISOR'])->assertSessionHasNoErrors();
        $deputy = SubGroup::where('nama', 'Deputy Project Manager')->firstOrFail();
        $this->put(self::BASE."/sub-group/{$deputy->id}", ['nama' => $deputy->nama, 'layer' => 4, 'sumber' => 'work_location', 'gelar' => 'DEPUTY PM'])->assertSessionHasNoErrors();
        $this->assertSame('DEPUTY PM', $deputy->fresh()->gelar);
        $this->post(self::BASE.'/sub-group', ['nama' => 'Supervisor', 'layer' => 2, 'sumber' => 'bidang', 'gelar' => ' supervisor '])->assertSessionHasNoErrors();
        $this->assertSame('SUPERVISOR', SubGroup::where('nama', 'Supervisor')->value('gelar'), 'titles are stored in capitals');

        $manager = SubGroup::where('nama', 'Manager')->firstOrFail();
        $this->delete(self::BASE."/sub-group/{$manager->id}")->assertSessionHasErrors('nama');

        // Data Lengkap: SubGroup and the composed title.
        $response = $this->get('/manpower/karyawan/data-lengkap');
        $keys = array_column($response->inertiaProps('groups.0.columns'), 'key');
        $this->assertContains('sub_group', $keys);
        $this->assertNotContains('status', $keys);
        $rows = collect($response->inertiaProps('rows'))->keyBy('name');
        $this->assertSame(['Manager', 'MANAGER HRGA', 'HRGA', null], [$rows['Siti Rahmawati']['sub_group'], $rows['Siti Rahmawati']['jabatan_name'], $rows['Siti Rahmawati']['departemen_name'], $rows['Siti Rahmawati']['section_name']]);
        $this->assertSame(['Operator', 'PRODUCTION OPERATOR', 'OPERASI TAMBANG'], [$rows['Yoga Pratama']['sub_group'], $rows['Yoga Pratama']['jabatan_name'], $rows['Yoga Pratama']['section_name']]);

        // Changing the rule renames the positions.
        $this->put(self::BASE."/sub-group/{$manager->id}", ['nama' => 'Department Manager', 'layer' => 4, 'sumber' => 'departemen', 'gelar' => 'DEPARTMENT MANAGER'])->assertSessionHasNoErrors();
        $rows = collect($this->get('/manpower/karyawan/data-lengkap')->inertiaProps('rows'))->keyBy('name');
        $this->assertSame(['Department Manager', 'DEPARTMENT MANAGER HRGA'], [$rows['Siti Rahmawati']['sub_group'], $rows['Siti Rahmawati']['jabatan_name']]);
    }

    public function test_work_location_comes_with_the_placement_and_decides_status_asal(): void
    {
        $hendra = $this->named('Hendra Wijaya'); // hired at Murung Raya (15), GENERAL MANAGER OPERASIONAL at Site Murung Raya
        $this->assertSame('Lokal', Nrp::derived($hendra)['status_asal']);

        // The location is part of the placement ("Ubah Penempatan"), not a free profile field.
        // Head Office has no GENERAL MANAGER OPERASIONAL in its plan: he waits to be placed there.
        $this->put("/manpower/karyawan/{$hendra->id}/penempatan", [
            ...JabatanComposer::placementOf($hendra->jabatan),
            'work_location_id' => WorkLocation::where('name', 'Head Office')->value('id'),
        ])->assertSessionHasNoErrors();
        $hendra->refresh();
        $this->assertSame(['Head Office', null], [$hendra->workLocation->name, $hendra->jabatan_id]);
        $this->assertSame('Kiriman', Nrp::derived($hendra)['status_asal']);
        $this->put("/manpower/karyawan/{$hendra->id}/profil", ['lokasi_kerja' => 'Site Rantau'])->assertSessionHasNoErrors();
        $this->assertSame('Head Office', $hendra->fresh()->workLocation->name, 'the profile form does not move anyone');

        // A location in use cannot be removed.
        $this->delete(self::BASE.'/work-location/'.WorkLocation::where('name', 'Head Office')->value('id'))->assertSessionHasErrors('name');

        // Excel: a new Work Location keeps the rest of the placement.
        $response = $this->post('/manpower/karyawan/excel/import', ['file' => $this->xlsx([
            ['NRP' => $hendra->nrp, 'Work Location' => 'RTU'],
            ['NRP' => $this->named('Yoga Pratama')->nrp, 'Work Location' => 'Pluto'],
        ])], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('Site Rantau', $hendra->fresh()->workLocation->name, 'found by its code');
        $this->assertSame('General Manager', $hendra->fresh()->subGroup->nama, 'the rest of the placement is kept');
        $this->assertStringContainsString('belum ada di Plan Struktur Organisasi', implode(' ', $response->json('rows.0.warnings')));
        $this->assertStringContainsString('Work Location "Pluto" tidak ada', $response->json('skipped.0.reason'));
    }

    // --- page & roles ------------------------------------------------------------------------------

    public function test_page_lists_every_master_with_usage(): void
    {
        $this->get(self::BASE)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manpower/MasterData/Index')
                ->has('jenis', 4)
                ->where('jenis.0.kode', '1')
                ->where('jenis.0.karyawan_count', 13)
                ->has('lokasi', 4)
                ->has('work_locations', 3)
                ->where('work_locations.0.karyawan_count', 5)
                ->has('sub_groups', 15)
                ->where('sub_groups.0.layer', 6)
                ->where('sub_groups.0.sumber', 'bod')
                ->has('jabatans', 11)
                ->has('penempatan_options.bidang', 5)
                ->has('options', count(MasterOptions::CATEGORIES))
                ->where('options.0.kategori', 'klasifikasi'));
    }

    public function test_viewers_cannot_open_or_change_master_data(): void
    {
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());

        $this->get(self::BASE)->assertForbidden();
        $this->post(self::BASE.'/jenis-karyawan', ['kode' => '5', 'nama' => 'x'])->assertForbidden();
        $this->post(self::BASE.'/opsi/agama', ['nilai' => 'x'])->assertForbidden();
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
