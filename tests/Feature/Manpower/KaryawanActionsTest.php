<?php

namespace Tests\Feature\Manpower;

use App\Models\Karyawan;
use App\Models\KaryawanAnak;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KaryawanActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/manpower/karyawan';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function named(string $name): Karyawan
    {
        return Karyawan::where('name', $name)->with('profile.pkwtPeriods', 'profile.anak')->firstOrFail();
    }

    private function jabatan(string $kode): int
    {
        return $this->seededJabatan($kode)->id;
    }

    private function lokasi(string $name = 'Site Murung Raya'): int
    {
        return WorkLocation::where('name', $name)->value('id');
    }

    // --- add ---------------------------------------------------------------------------

    public function test_adds_an_employee_with_the_essentials(): void
    {
        $this->from(self::BASE.'/data-lengkap')
            ->post(self::BASE, ['nrp' => '10124300', 'name' => 'Karyawan Baru', 'email' => '', 'jabatan_id' => $this->jabatan('OPR-PRD'), 'work_location_id' => $this->lokasi()])
            ->assertRedirect(self::BASE.'/data-lengkap')
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('karyawans', ['nrp' => '10124300', 'status' => 'Aktif', 'email' => null]);
    }

    public function test_add_rejects_a_taken_nrp_and_an_occupied_single_holder_position(): void
    {
        $taken = $this->named('Hendra Wijaya')->nrp;
        $this->post(self::BASE, ['nrp' => $taken, 'name' => 'Dobel', 'jabatan_id' => $this->jabatan('OPR-PRD'), 'work_location_id' => $this->lokasi()])
            ->assertSessionHasErrors(['nrp' => "NRP \"{$taken}\" sudah digunakan."]);

        $this->post(self::BASE, ['nrp' => '10124301', 'name' => 'Rebut', 'jabatan_id' => $this->jabatan('KDP-HRG'), 'work_location_id' => $this->lokasi()])
            ->assertSessionHasErrors('penempatan');
        $this->assertStringContainsString('Siti Rahmawati', session('errors')->first('penempatan'));
    }

    public function test_add_dialog_loads_the_placement_choices_on_demand(): void
    {
        $this->get(self::BASE.'/data-lengkap')
            ->assertInertia(fn (Assert $page) => $page->missing('penempatan_options')->has('alasan_options', 8));

        // The partial reload the dialog makes when it opens.
        $options = $this->get(self::BASE.'/data-lengkap', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Manpower/Karyawan/DataLengkap',
            'X-Inertia-Partial-Data' => 'penempatan_options',
        ])->assertOk()->json('props.penempatan_options');

        $this->assertCount(15, $options['sub_groups']);
        $this->assertContains('MECHANIC', $options['bidang']);
        $hrga = collect($options['jabatans'])->firstWhere('id', $this->jabatan('KDP-HRG'));
        $this->assertSame(['Siti Rahmawati'], $hrga['holders']);
    }

    // --- exit, restore, delete ------------------------------------------------------------

    public function test_records_an_exit_and_moves_the_employee_to_karyawan_keluar(): void
    {
        $fajar = $this->named('Fajar Nugroho');

        $this->from(self::BASE.'/data-lengkap')
            ->post(self::BASE."/{$fajar->id}/keluar", ['alasan' => 'PKWT Berakhir', 'tanggal' => '2026-10-15', 'keterangan' => 'Kontrak tidak diperpanjang'])
            ->assertRedirect(self::BASE.'/data-lengkap')
            ->assertInertiaFlash('success');

        $fajar->refresh();
        $this->assertSame('Keluar', $fajar->status);
        $this->assertSame('PKWT Berakhir', $fajar->alasan_keluar);
        $this->assertSame('2026-10-15', $fajar->tanggal_keluar->toDateString());

        $this->get(self::BASE.'/keluar')->assertInertia(fn (Assert $page) => $page->has('rows', 2));
        $this->get(self::BASE.'/data-lengkap')->assertInertia(fn (Assert $page) => $page->has('rows', 13));
    }

    public function test_exit_validation(): void
    {
        $fajar = $this->named('Fajar Nugroho');
        $url = self::BASE."/{$fajar->id}/keluar";

        $this->post($url, ['alasan' => 'Kabur', 'tanggal' => '2026-10-15'])->assertSessionHasErrors('alasan');
        $this->post($url, ['alasan' => 'Resign', 'tanggal' => '15-10-2026'])->assertSessionHasErrors('tanggal');
        $this->post($url, ['alasan' => 'Resign', 'tanggal' => '2000-01-01'])
            ->assertSessionHasErrors(['tanggal' => "Tanggal keluar tidak boleh sebelum tanggal masuk ({$fajar->profile->tanggal_masuk->format('d.m.Y')})."]);

        $wahyu = $this->named('Wahyu Kurniawan');
        $this->post(self::BASE."/{$wahyu->id}/keluar", ['alasan' => 'Resign', 'tanggal' => '2026-10-15'])
            ->assertSessionHasErrors(['alasan' => 'Karyawan ini sudah tercatat keluar.']);
    }

    public function test_restores_an_exited_employee(): void
    {
        $wahyu = $this->named('Wahyu Kurniawan');

        $this->delete(self::BASE."/{$wahyu->id}/keluar")->assertSessionHasNoErrors()->assertInertiaFlash('success');

        $wahyu->refresh();
        $this->assertSame('Aktif', $wahyu->status);
        $this->assertNull($wahyu->alasan_keluar);
        $this->assertNull($wahyu->tanggal_keluar);
    }

    public function test_restored_employee_waits_to_be_placed_when_the_old_position_was_filled(): void
    {
        $siti = $this->named('Siti Rahmawati');
        $this->post(self::BASE."/{$siti->id}/keluar", ['alasan' => 'Resign', 'tanggal' => '2026-09-26']);
        $this->post(self::BASE, ['nrp' => '10124302', 'name' => 'Pengganti Siti', 'jabatan_id' => $this->jabatan('KDP-HRG'), 'work_location_id' => $this->lokasi('Head Office')])->assertSessionHasNoErrors();

        $this->delete(self::BASE."/{$siti->id}/keluar")->assertSessionHasNoErrors();

        $siti->refresh();
        $this->assertSame(['Aktif', null], [$siti->status, $siti->jabatan_id], 'active again, waiting in the Struktur Organisasi list');
        $this->assertSame('MANAGER', $siti->subGroup->gelar, 'her placement is kept');
    }

    public function test_permanent_delete_needs_the_nrp_typed_back(): void
    {
        $lina = $this->named('Lina Agustina');
        $profileId = $lina->profile->id;

        $this->delete(self::BASE."/{$lina->id}", ['nrp_konfirmasi' => '123'])->assertSessionHasErrors('nrp_konfirmasi');
        $this->assertModelExists($lina);

        $this->delete(self::BASE."/{$lina->id}", ['nrp_konfirmasi' => $lina->nrp])->assertSessionHasNoErrors();

        $this->assertModelMissing($lina);
        $this->assertDatabaseMissing('karyawan_profiles', ['id' => $profileId]);
        $this->assertDatabaseMissing('pkwt_periods', ['karyawan_profile_id' => $profileId]);
    }

    // --- profile ----------------------------------------------------------------------------

    public function test_profile_page_shows_all_sections_and_computed_values(): void
    {
        $rudi = $this->named('Rudi Hartono');

        $this->get(self::BASE."/{$rudi->id}/profil")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manpower/Karyawan/Profile')
                ->where('karyawan.nrp', $rudi->nrp)
                ->where('karyawan.jabatan', 'MECHANIC')
                ->has('sections', 19)
                ->where('profile.tanggal_lahir', '1988-03-30')
                ->where('profile.status_pegawai_tetap', false)
                ->where('computed.usia', $rudi->profile->usia)
                ->where('computed.status_akhir_pkwt', 'Aktif')
                ->has('pkwt', 3)
                ->where('pkwt.2.label', 'PKWT III')
                ->has('anak', 2)
                ->where('pkwt_expiring_soon', false) // 55 days left
                ->where('options.max_pkwt', 6));

        $fajar = $this->named('Fajar Nugroho');
        $this->get(self::BASE."/{$fajar->id}/profil")->assertInertia(fn (Assert $page) => $page->where('pkwt_expiring_soon', true));
    }

    public function test_profile_update_saves_and_warns_about_a_different_account_holder(): void
    {
        $maya = $this->named('Maya Sari');

        $this->from(self::BASE."/{$maya->id}/profil")
            ->put(self::BASE."/{$maya->id}/profil", [
                'nik' => $maya->profile->nik,
                'tanggal_lahir' => '2000-12-05',
                'golongan' => '3C',
                'status_perkawinan' => 'Menikah',
                'tanggal_perkawinan' => '2026-08-17',
                'status_pegawai_tetap' => true,
                'nama_pemilik_rekening' => 'Budi Pratama',
                'agama' => '',
            ])
            ->assertRedirect(self::BASE."/{$maya->id}/profil")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'Profil tersimpan.')
            ->assertInertiaFlash('warnings');

        $profile = $maya->profile->fresh();
        $this->assertSame('3C', $profile->golongan);
        // A golongan not in Master Data Comben is refused.
        $this->put(self::BASE."/{$maya->id}/profil", ['golongan' => 'II-C'])->assertSessionHasErrors('golongan');
        $this->assertSame('2026-08-17', $profile->tanggal_perkawinan->toDateString());
        $this->assertTrue($profile->status_pegawai_tetap);
        $this->assertNull($profile->agama, 'an emptied field is cleared');
    }

    public function test_profile_validation(): void
    {
        $maya = $this->named('Maya Sari');
        $siti = $this->named('Siti Rahmawati');
        $url = self::BASE."/{$maya->id}/profil";

        $this->put($url, ['nik' => '12345', 'npwp' => '12', 'bpjs_tk' => '12A'])->assertSessionHasErrors([
            'nik' => 'NIK harus 16 digit angka.',
            'npwp' => 'NPWP harus 15 atau 16 digit angka.',
            'bpjs_tk' => 'Nomor BPJS Ketenagakerjaan harus berupa angka.',
        ]);

        $this->put($url, ['nik' => $siti->profile->nik])
            ->assertSessionHasErrors(['nik' => "NIK (KTP) \"{$siti->profile->nik}\" sudah dipakai karyawan \"Siti Rahmawati\"."]);

        $this->put($url, ['agama' => 'Jedi', 'tanggal_sk' => '31/02/2026', 'tahun_lulus' => 'dua ribu'])->assertSessionHasErrors([
            'agama' => 'Agama yang dipilih tidak valid.',
            'tanggal_sk' => 'Tanggal SK bukan tanggal yang valid.',
            'tahun_lulus' => 'Tahun Lulus harus berupa angka bulat.',
        ]);

        // Its own NIK is not a duplicate.
        $this->put($url, ['nik' => $maya->profile->nik])->assertSessionHasNoErrors();
    }

    // --- PKWT ---------------------------------------------------------------------------------

    public function test_extends_a_contract_as_the_next_period(): void
    {
        $fajar = $this->named('Fajar Nugroho');

        $this->post(self::BASE."/{$fajar->id}/pkwt", ['tanggal_kontrak' => '2026-10-16', 'tanggal_berakhir' => '2027-10-15'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'PKWT II untuk Fajar Nugroho tersimpan.');

        $this->assertSame([1, 2], $fajar->profile->pkwtPeriods()->pluck('periode_ke')->all());

        // Fajar has left the "ending within a month" list.
        $response = $this->get(self::BASE);
        $this->assertNotContains('Fajar Nugroho', array_column($response->inertiaProps('dashboard.pkwt_akan_berakhir'), 'name'));
    }

    public function test_pkwt_rules(): void
    {
        $rudi = $this->named('Rudi Hartono'); // 3 periods
        $url = self::BASE."/{$rudi->id}/pkwt";

        $this->post($url, ['tanggal_kontrak' => '2027-01-01', 'tanggal_berakhir' => '2026-01-01'])
            ->assertSessionHasErrors(['tanggal_berakhir' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal kontrak.']);

        foreach (range(4, 6) as $n) {
            $this->post($url, ['tanggal_kontrak' => "20{$n}0-01-01", 'tanggal_berakhir' => "20{$n}0-12-31"])->assertSessionHasNoErrors();
        }
        $this->post($url, ['tanggal_kontrak' => '2070-01-01', 'tanggal_berakhir' => '2070-12-31'])
            ->assertSessionHasErrors(['tanggal_kontrak' => 'Maksimal 6 periode PKWT. Gunakan Pengangkatan Pegawai untuk status tetap.']);
    }

    public function test_updating_and_deleting_periods_keeps_numbering_contiguous(): void
    {
        $rudi = $this->named('Rudi Hartono');
        [$first, $second, $third] = $rudi->profile->pkwtPeriods->all();

        $this->put(self::BASE."/{$rudi->id}/pkwt/{$third->id}", ['tanggal_kontrak' => $third->tanggal_kontrak->toDateString(), 'tanggal_berakhir' => '2027-06-30'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-06-30', $third->fresh()->tanggal_berakhir->toDateString());

        $this->delete(self::BASE."/{$rudi->id}/pkwt/{$second->id}")->assertSessionHasNoErrors();

        $this->assertSame([1, 2], $rudi->profile->pkwtPeriods()->pluck('periode_ke')->all());
        $this->assertSame(2, $third->fresh()->periode_ke);
    }

    public function test_a_period_can_only_be_changed_through_its_own_employee(): void
    {
        $rudi = $this->named('Rudi Hartono');
        $maya = $this->named('Maya Sari');
        $period = $rudi->profile->pkwtPeriods->first();

        $this->delete(self::BASE."/{$maya->id}/pkwt/{$period->id}")->assertNotFound();
        $this->assertModelExists($period);
    }

    // --- children ------------------------------------------------------------------------------

    public function test_children_can_be_added_edited_and_removed(): void
    {
        $joko = $this->named('Joko Santoso'); // 3 children
        $base = self::BASE."/{$joko->id}/anak";

        $this->post($base, ['nama' => 'Anak Baru'])->assertSessionHasNoErrors();
        $newest = KaryawanAnak::where('nama', 'Anak Baru')->firstOrFail();
        $this->assertSame(4, $newest->urutan);

        $this->put("{$base}/{$newest->id}", ['nama' => 'Theresia Santoso', 'tanggal_lahir' => '2005-02-01', 'pendidikan' => 'S1', 'status_bekerja' => 'Belum Bekerja'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Theresia Santoso', $newest->fresh()->nama);

        $this->put("{$base}/{$newest->id}", ['nama' => '', 'pendidikan' => 'Kuliah'])->assertSessionHasErrors(['nama', 'pendidikan']);

        $this->delete("{$base}/{$joko->profile->anak->first()->id}")->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3], $joko->profile->anak()->pluck('urutan')->all());
        $this->assertSame(3, $newest->fresh()->urutan);
    }

    // --- roles -----------------------------------------------------------------------------------

    public function test_viewers_cannot_open_profiles_or_change_anything(): void
    {
        $rudi = $this->named('Rudi Hartono');
        $period = $rudi->profile->pkwtPeriods->first();
        $this->actingAs(User::where('email', 'viewer@hrpuspita.local')->firstOrFail());

        $this->get(self::BASE."/{$rudi->id}/profil")->assertForbidden();
        $this->put(self::BASE."/{$rudi->id}/profil", ['golongan' => 'X'])->assertForbidden();
        $this->post(self::BASE, ['nrp' => '1', 'name' => 'x', 'jabatan_id' => 1])->assertForbidden();
        $this->post(self::BASE."/{$rudi->id}/keluar", ['alasan' => 'Resign', 'tanggal' => '2026-10-01'])->assertForbidden();
        $this->delete(self::BASE."/{$rudi->id}", ['nrp_konfirmasi' => $rudi->nrp])->assertForbidden();
        $this->delete(self::BASE."/{$rudi->id}/pkwt/{$period->id}")->assertForbidden();
        $this->post(self::BASE."/{$rudi->id}/anak", ['nama' => 'x'])->assertForbidden();

        $this->assertSame('Aktif', $rudi->fresh()->status);
        $this->assertModelExists($period);
    }
}
