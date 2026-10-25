<?php

namespace Tests\Feature\CoreHr;

use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/core-hr/employees';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    private function named(string $name): Employee
    {
        return Employee::where('name', $name)->with('profile.contracts', 'profile.children')->firstOrFail();
    }

    private function position(string $code): int
    {
        return $this->seededPosition($code)->id;
    }

    private function location(string $name = 'Site Murung Raya'): int
    {
        return WorkLocation::where('name', $name)->value('id');
    }

    // --- add ---------------------------------------------------------------------------

    public function test_adds_an_employee_with_the_essentials(): void
    {
        $this->from(self::BASE.'/full-data')
            ->post(self::BASE, ['employee_number' => '10124300', 'name' => 'Karyawan Baru', 'email' => '', 'position_id' => $this->position('OPR-PRD'), 'work_location_id' => $this->location()])
            ->assertRedirect(self::BASE.'/full-data')
            ->assertInertiaFlash('success');

        $this->assertDatabaseHas('employees', ['employee_number' => '10124300', 'status' => Employee::STATUS_ACTIVE, 'email' => null]);
    }

    public function test_add_rejects_a_taken_nrp_and_an_occupied_single_holder_position(): void
    {
        $taken = $this->named('Hendra Wijaya')->employee_number;
        $this->post(self::BASE, ['employee_number' => $taken, 'name' => 'Dobel', 'position_id' => $this->position('OPR-PRD'), 'work_location_id' => $this->location()])
            ->assertSessionHasErrors(['employee_number' => "NRP \"{$taken}\" sudah digunakan."]);

        $this->post(self::BASE, ['employee_number' => '10124301', 'name' => 'Rebut', 'position_id' => $this->position('KDP-HRG'), 'work_location_id' => $this->location()])
            ->assertSessionHasErrors('placement');
        $this->assertStringContainsString('Siti Rahmawati', session('errors')->first('placement'));
    }

    public function test_add_dialog_loads_the_placement_choices_on_demand(): void
    {
        $this->get(self::BASE.'/full-data')
            ->assertInertia(fn (Assert $page) => $page->missing('placement_options')->has('reason_options', 8));

        // The partial reload the dialog makes when it opens.
        $options = $this->get(self::BASE.'/full-data', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'CoreHr/Employees/FullData',
            'X-Inertia-Partial-Data' => 'placement_options',
        ])->assertOk()->json('props.placement_options');

        $this->assertCount(15, $options['job_levels']);
        $this->assertContains('MECHANIC', $options['discipline']);
        $hrga = collect($options['positions'])->firstWhere('id', $this->position('KDP-HRG'));
        $this->assertSame(['Siti Rahmawati'], $hrga['holders']);
    }

    // --- exit, restore, delete ------------------------------------------------------------

    public function test_records_an_exit_and_moves_the_employee_to_karyawan_keluar(): void
    {
        $fajar = $this->named('Fajar Nugroho');

        $this->from(self::BASE.'/full-data')
            ->post(self::BASE."/{$fajar->id}/termination", ['reason' => 'PKWT Berakhir', 'date' => '2026-10-15', 'description' => 'Kontrak tidak diperpanjang'])
            ->assertRedirect(self::BASE.'/full-data')
            ->assertInertiaFlash('success');

        $fajar->refresh();
        $this->assertSame(Employee::STATUS_TERMINATED, $fajar->status);
        $this->assertSame('PKWT Berakhir', $fajar->termination_reason);
        $this->assertSame('2026-10-15', $fajar->termination_date->toDateString());

        $this->get(self::BASE.'/termination')->assertInertia(fn (Assert $page) => $page->has('rows', 2));
        $this->get(self::BASE.'/full-data')->assertInertia(fn (Assert $page) => $page->has('rows', 13));
    }

    public function test_exit_validation(): void
    {
        $fajar = $this->named('Fajar Nugroho');
        $url = self::BASE."/{$fajar->id}/termination";

        $this->post($url, ['reason' => 'Kabur', 'date' => '2026-10-15'])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'Resign', 'date' => '15-10-2026'])->assertSessionHasErrors('date');
        $this->post($url, ['reason' => 'Resign', 'date' => '2000-01-01'])
            ->assertSessionHasErrors(['date' => "Tanggal keluar tidak boleh sebelum tanggal masuk ({$fajar->profile->hire_date->format('d.m.Y')})."]);

        $wahyu = $this->named('Wahyu Kurniawan');
        $this->post(self::BASE."/{$wahyu->id}/termination", ['reason' => 'Resign', 'date' => '2026-10-15'])
            ->assertSessionHasErrors(['reason' => 'Karyawan ini sudah tercatat keluar.']);
    }

    public function test_restores_an_exited_employee(): void
    {
        $wahyu = $this->named('Wahyu Kurniawan');

        $this->delete(self::BASE."/{$wahyu->id}/termination")->assertSessionHasNoErrors()->assertInertiaFlash('success');

        $wahyu->refresh();
        $this->assertSame(Employee::STATUS_ACTIVE, $wahyu->status);
        $this->assertNull($wahyu->termination_reason);
        $this->assertNull($wahyu->termination_date);
    }

    public function test_restored_employee_waits_to_be_placed_when_the_old_position_was_filled(): void
    {
        $siti = $this->named('Siti Rahmawati');
        $this->post(self::BASE."/{$siti->id}/termination", ['reason' => 'Resign', 'date' => '2026-09-26']);
        $this->post(self::BASE, ['employee_number' => '10124302', 'name' => 'Pengganti Siti', 'position_id' => $this->position('KDP-HRG'), 'work_location_id' => $this->location('Head Office')])->assertSessionHasNoErrors();

        $this->delete(self::BASE."/{$siti->id}/termination")->assertSessionHasNoErrors();

        $siti->refresh();
        $this->assertSame([Employee::STATUS_ACTIVE, null], [$siti->status, $siti->position_id], 'active again, waiting in the Struktur Organisasi list');
        $this->assertSame('MANAGER', $siti->jobLevel->title_prefix, 'her placement is kept');
    }

    public function test_permanent_delete_needs_the_nrp_typed_back(): void
    {
        $lina = $this->named('Lina Agustina');
        $profileId = $lina->profile->id;

        $this->delete(self::BASE."/{$lina->id}", ['employee_number_confirmation' => '123'])->assertSessionHasErrors('employee_number_confirmation');
        $this->assertModelExists($lina);

        $this->delete(self::BASE."/{$lina->id}", ['employee_number_confirmation' => $lina->employee_number])->assertSessionHasNoErrors();

        $this->assertModelMissing($lina);
        $this->assertDatabaseMissing('employee_profiles', ['id' => $profileId]);
        $this->assertDatabaseMissing('employment_contracts', ['employee_profile_id' => $profileId]);
    }

    // --- profile ----------------------------------------------------------------------------

    public function test_profile_page_shows_all_sections_and_computed_values(): void
    {
        $rudi = $this->named('Rudi Hartono');

        $this->get(self::BASE."/{$rudi->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CoreHr/Employees/Profile')
                ->where('employee.employee_number', $rudi->employee_number)
                ->where('employee.position', 'MECHANIC')
                ->has('sections', 20)
                ->where('profile.birth_date', '1988-03-30')
                ->where('profile.is_permanent', false)
                ->where('computed.age', $rudi->profile->age)
                ->where('computed.contract_end_status', 'Aktif')
                ->has('contract', 3)
                ->where('contract.2.label', 'PKWT III')
                ->has('children', 2)
                ->where('contract_expiring_soon', false) // 55 days left
                ->where('options.max_contract', 6));

        $fajar = $this->named('Fajar Nugroho');
        $this->get(self::BASE."/{$fajar->id}/profile")->assertInertia(fn (Assert $page) => $page->where('contract_expiring_soon', true));
    }

    public function test_profile_update_saves_and_warns_about_a_different_account_holder(): void
    {
        $maya = $this->named('Maya Sari');

        $this->from(self::BASE."/{$maya->id}/profile")
            ->put(self::BASE."/{$maya->id}/profile", [
                'national_id_number' => $maya->profile->national_id_number,
                'birth_date' => '2000-12-05',
                'grade' => '3C',
                'marital_status' => 'Menikah',
                'marriage_date' => '2026-08-17',
                'is_permanent' => true,
                'bank_account_holder' => 'Budi Pratama',
                'religion' => '',
            ])
            ->assertRedirect(self::BASE."/{$maya->id}/profile")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'Profil tersimpan.')
            ->assertInertiaFlash('warnings');

        $profile = $maya->profile->fresh();
        $this->assertSame('3C', $profile->grade);
        // A golongan not in Pengaturan Kompensasi is refused.
        $this->put(self::BASE."/{$maya->id}/profile", ['grade' => 'II-C'])->assertSessionHasErrors('grade');
        $this->assertSame('2026-08-17', $profile->marriage_date->toDateString());
        $this->assertTrue($profile->is_permanent);
        $this->assertNull($profile->religion, 'an emptied field is cleared');
    }

    public function test_profile_validation(): void
    {
        $maya = $this->named('Maya Sari');
        $siti = $this->named('Siti Rahmawati');
        $url = self::BASE."/{$maya->id}/profile";

        $this->put($url, ['national_id_number' => '12345', 'tax_id_number' => '12', 'bpjs_employment_number' => '12A'])->assertSessionHasErrors([
            'national_id_number' => 'NIK harus 16 digit angka.',
            'tax_id_number' => 'NPWP harus 15 atau 16 digit angka.',
            'bpjs_employment_number' => 'Nomor BPJS Ketenagakerjaan harus berupa angka.',
        ]);

        $this->put($url, ['national_id_number' => $siti->profile->national_id_number])
            ->assertSessionHasErrors(['national_id_number' => "NIK (KTP) \"{$siti->profile->national_id_number}\" sudah dipakai karyawan \"Siti Rahmawati\"."]);

        $this->put($url, ['religion' => 'Jedi', 'appointment_letter_date' => '31/02/2026', 'graduation_year' => 'dua ribu'])->assertSessionHasErrors([
            'religion' => 'Agama yang dipilih tidak valid.',
            'appointment_letter_date' => 'Tanggal SK bukan tanggal yang valid.',
            'graduation_year' => 'Tahun Lulus harus berupa angka bulat.',
        ]);

        // Its own NIK is not a duplicate.
        $this->put($url, ['national_id_number' => $maya->profile->national_id_number])->assertSessionHasNoErrors();
    }

    // --- PKWT ---------------------------------------------------------------------------------

    public function test_extends_a_contract_as_the_next_period(): void
    {
        $fajar = $this->named('Fajar Nugroho');

        $this->post(self::BASE."/{$fajar->id}/contract", ['start_date' => '2026-10-16', 'end_date' => '2027-10-15'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'PKWT II untuk Fajar Nugroho tersimpan.');

        $this->assertSame([1, 2], $fajar->profile->contracts()->pluck('sequence_no')->all());

        // Fajar has left the "ending within a month" list.
        $response = $this->get(self::BASE);
        $this->assertNotContains('Fajar Nugroho', array_column($response->inertiaProps('dashboard.contracts_ending_soon'), 'name'));
    }

    public function test_pkwt_rules(): void
    {
        $rudi = $this->named('Rudi Hartono'); // 3 periods
        $url = self::BASE."/{$rudi->id}/contract";

        $this->post($url, ['start_date' => '2027-01-01', 'end_date' => '2026-01-01'])
            ->assertSessionHasErrors(['end_date' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal kontrak.']);

        foreach (range(4, 6) as $n) {
            $this->post($url, ['start_date' => "20{$n}0-01-01", 'end_date' => "20{$n}0-12-31"])->assertSessionHasNoErrors();
        }
        $this->post($url, ['start_date' => '2070-01-01', 'end_date' => '2070-12-31'])
            ->assertSessionHasErrors(['start_date' => 'Maksimal 6 periode PKWT. Gunakan Pengangkatan Pegawai untuk status tetap.']);
    }

    public function test_updating_and_deleting_periods_keeps_numbering_contiguous(): void
    {
        $rudi = $this->named('Rudi Hartono');
        [$first, $second, $third] = $rudi->profile->contracts->all();

        $this->put(self::BASE."/{$rudi->id}/contract/{$third->id}", ['start_date' => $third->start_date->toDateString(), 'end_date' => '2027-06-30'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-06-30', $third->fresh()->end_date->toDateString());

        $this->delete(self::BASE."/{$rudi->id}/contract/{$second->id}")->assertSessionHasNoErrors();

        $this->assertSame([1, 2], $rudi->profile->contracts()->pluck('sequence_no')->all());
        $this->assertSame(2, $third->fresh()->sequence_no);
    }

    public function test_a_period_can_only_be_changed_through_its_own_employee(): void
    {
        $rudi = $this->named('Rudi Hartono');
        $maya = $this->named('Maya Sari');
        $period = $rudi->profile->contracts->first();

        $this->delete(self::BASE."/{$maya->id}/contract/{$period->id}")->assertNotFound();
        $this->assertModelExists($period);
    }

    // --- children ------------------------------------------------------------------------------

    public function test_children_can_be_added_edited_and_removed(): void
    {
        $joko = $this->named('Joko Santoso'); // 3 children
        $base = self::BASE."/{$joko->id}/children";

        $this->post($base, ['name' => 'Anak Baru'])->assertSessionHasNoErrors();
        $newest = EmployeeChild::where('name', 'Anak Baru')->firstOrFail();
        $this->assertSame(4, $newest->sort_order);

        $this->put("{$base}/{$newest->id}", ['name' => 'Theresia Santoso', 'birth_date' => '2005-02-01', 'education_level' => 'S1', 'employment_status' => 'Belum Bekerja'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Theresia Santoso', $newest->fresh()->name);

        $this->put("{$base}/{$newest->id}", ['name' => '', 'education_level' => 'Kuliah'])->assertSessionHasErrors(['name', 'education_level']);

        $this->delete("{$base}/{$joko->profile->children->first()->id}")->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3], $joko->profile->children()->pluck('sort_order')->all());
        $this->assertSame(3, $newest->fresh()->sort_order);
    }

    // --- roles -----------------------------------------------------------------------------------

    public function test_viewers_cannot_open_profiles_or_change_anything(): void
    {
        $rudi = $this->named('Rudi Hartono');
        $period = $rudi->profile->contracts->first();
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());

        $this->get(self::BASE."/{$rudi->id}/profile")->assertForbidden();
        $this->put(self::BASE."/{$rudi->id}/profile", ['grade' => 'X'])->assertForbidden();
        $this->post(self::BASE, ['employee_number' => '1', 'name' => 'x', 'position_id' => 1])->assertForbidden();
        $this->post(self::BASE."/{$rudi->id}/termination", ['reason' => 'Resign', 'date' => '2026-10-01'])->assertForbidden();
        $this->delete(self::BASE."/{$rudi->id}", ['employee_number_confirmation' => $rudi->employee_number])->assertForbidden();
        $this->delete(self::BASE."/{$rudi->id}/contract/{$period->id}")->assertForbidden();
        $this->post(self::BASE."/{$rudi->id}/children", ['name' => 'x'])->assertForbidden();

        $this->assertSame(Employee::STATUS_ACTIVE, $rudi->fresh()->status);
        $this->assertModelExists($period);
    }
}
