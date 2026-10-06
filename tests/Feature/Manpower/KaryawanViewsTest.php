<?php

namespace Tests\Feature\Manpower;

use App\Models\Karyawan;
use App\Models\User;
use App\Support\Karyawan\Completeness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Runs against the demo seed, whose scenarios are relative to today (see KaryawanSeeder). */
class KaryawanViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Explicit: the schema is migrated once per run, so a $seed flag only works for the first test class.
        $this->seed();
    }

    private function hr(): User
    {
        return User::where('role', User::ROLE_HR)->firstOrFail();
    }

    public function test_viewers_get_the_access_denied_page_on_every_view(): void
    {
        $viewer = User::where('email', 'viewer@hrpuspita.local')->firstOrFail();

        foreach (['/manpower/karyawan', '/manpower/karyawan/data-lengkap', '/manpower/karyawan/keluar'] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertForbidden()
                ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
        }
    }

    public function test_dashboard_counts_active_employees_only(): void
    {
        $this->actingAs($this->hr())->get('/manpower/karyawan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manpower/Karyawan/Dashboard')
                ->where('dashboard.total_aktif', 14) // 15 seeded, 1 resigned
                ->where('dashboard.asal', ['lokal' => 11, 'kiriman' => 3, 'belum_diisi' => 0]) // Agus, Indah, Dimas
                ->where('dashboard.status_pegawai', ['pkwt' => 8, 'tetap' => 6])
                ->has('dashboard.completeness.groups', 19));
    }

    public function test_dashboard_follow_up_lists(): void
    {
        $response = $this->actingAs($this->hr())->get('/manpower/karyawan');
        $names = fn (string $key) => array_column($response->inertiaProps("dashboard.$key"), 'name');

        // Soonest first; Rudi's contract (55 days) is outside the 1-month window.
        $this->assertSame(['Fajar Nugroho', 'Yoga Pratama'], $names('pkwt_akan_berakhir'));
        // Dewi's old contract lapsed too, but she is permanent so it is not a follow-up.
        $this->assertSame(['Maya Sari'], $names('pkwt_berakhir'));
        $this->assertSame(['Joko Santoso', 'Hendra Wijaya'], $names('pensiun'));
        // Born this month, by day: Indah (8th) and Siti (20th) always, plus whoever's fixed demo birthday falls in this month.
        $bornThisMonth = Karyawan::aktif()->with('profile')->get()
            ->filter(fn (Karyawan $k) => $k->profile?->tanggal_lahir?->month === today()->month)
            ->sortBy(fn (Karyawan $k) => $k->profile->tanggal_lahir->day)
            ->pluck('name')->values()->all();
        $this->assertSame($bornThisMonth, $names('ulang_tahun'));
        $this->assertContains('Indah Permatasari', $bornThisMonth);
        $this->assertContains('Siti Rahmawati', $bornThisMonth);

        $fajar = $response->inertiaProps('dashboard.pkwt_akan_berakhir.0');
        $this->assertSame(19, $fajar['sisa_hari']);
        $this->assertSame(today()->addDays(20)->toDateString(), $fajar['next_start']);
    }

    public function test_data_lengkap_lists_everyone_but_exited_employees(): void
    {
        $response = $this->actingAs($this->hr())->get('/manpower/karyawan/data-lengkap')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manpower/Karyawan/DataLengkap')
                ->has('rows', 14)
                ->has('groups', 19)
                ->where('groups.0.locked', true));

        $rows = collect($response->inertiaProps('rows'))->keyBy('name');
        $this->assertArrayNotHasKey('Wahyu Kurniawan', $rows);

        $rudi = $rows['Rudi Hartono'];
        $this->assertSame('PKWT III', $rudi['pkwt_terakhir_periode']);
        $this->assertTrue($rudi['pkwt_terakhir_hampir_habis']); // 55 days: inside the 2-month warning
        $this->assertFalse($rows['Rina Marlina']['pkwt_terakhir_hampir_habis']);

        // Dates stay plain Y-m-d, with no timezone shift.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $rudi['tanggal_lahir']);
        $this->assertSame('1988-03-30', $rudi['tanggal_lahir']);

        // Permanent staff still show their last (lapsed) contract.
        $this->assertSame('Berakhir', $rows['Dewi Lestari']['status_akhir_pkwt']);
        $this->assertNull($rows['Lina Agustina']['nik']);
        // Read from the NRP: Rudi 1-03-23-101 = Karyawan Normal hired at Rantau, working at Site Rantau.
        $this->assertSame('Karyawan Normal', $rudi['jenis_karyawan']);
        $this->assertSame('Rantau', $rudi['tempat_penerimaan']);
        $this->assertSame('Lokal', $rudi['status_asal']);
        // Indah was hired at Murung Raya and works at Head Office.
        $this->assertSame('Kiriman', $rows['Indah Permatasari']['status_asal']);
        $this->assertSame('Operator Magang', $rows['Lina Agustina']['jenis_karyawan']);
    }

    public function test_keluar_lists_exited_employees_with_service_up_to_the_exit_date(): void
    {
        $wahyu = Karyawan::where('name', 'Wahyu Kurniawan')->with('profile')->firstOrFail();
        $expected = $wahyu->profile->masaKerjaPada($wahyu->tanggal_keluar);

        $this->actingAs($this->hr())->get('/manpower/karyawan/keluar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manpower/Karyawan/Keluar')
                ->has('rows', 1)
                ->where('rows.0.name', 'Wahyu Kurniawan')
                ->where('rows.0.alasan_keluar', 'Resign')
                ->where('rows.0.masa_kerja', $expected)
                ->has('alasan_options', 8));

        $this->assertNotSame($wahyu->profile->masa_kerja, $expected, 'masa kerja must stop at the exit date');
    }

    public function test_completeness_skips_groups_that_do_not_apply(): void
    {
        // Dewi: permanent and single — no PKWT, spouse, in-laws or children to fill.
        $dewi = Karyawan::where('name', 'Dewi Lestari')->with('profile.pkwtPeriods', 'profile.anak')->firstOrFail();
        $scores = Completeness::score($dewi->profile);

        foreach (['pkwt', 'pasangan', 'mertua', 'anak'] as $group) {
            $this->assertFalse($scores[$group]['applicable'], $group);
        }
        $this->assertTrue($scores['pengangkatan']['applicable']);
        $this->assertSame(2, $scores['status-pegawai']['total'], 'no marriage date for someone single');
        $this->assertSame(100, Completeness::overallPercent($scores));

        // Lina: deliberately incomplete profile.
        $lina = Karyawan::where('name', 'Lina Agustina')->with('profile.pkwtPeriods', 'profile.anak')->firstOrFail();
        $this->assertLessThan(50, Completeness::overallPercent(Completeness::score($lina->profile)));
    }
}
