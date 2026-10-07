<?php

namespace Tests\Feature;

use App\Http\Controllers\Settings\UserController;
use App\Models\Deklarasi;
use App\Models\Karyawan;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00');
        CarbonImmutable::setTestNow('2026-10-07 09:00:00');
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function employee(): Karyawan
    {
        return Karyawan::where('status', Karyawan::STATUS_AKTIF)->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
    }

    private function book(TestResponse $response): Spreadsheet
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        return $book;
    }

    /** Every cell value of a sheet as one string, to look for a value anywhere. */
    private function text(Spreadsheet $book, string $sheet): string
    {
        return implode('|', array_map(fn (array $row) => implode('|', array_map('strval', $row)), $book->getSheetByNameOrThrow($sheet)->toArray(null, false, false)));
    }

    public function test_deklarasi_export_has_a_rekap_and_a_rincian_sheet(): void
    {
        $k = $this->employee();
        $d = Deklarasi::create([
            'nomor' => 'DKL/2026/10/0001', 'karyawan_id' => $k->id, 'tanggal' => '2026-10-02', 'jumlah_kwitansi' => 2,
            'bulan_buku' => '2026-10', 'tahun_plafon' => 2026, 'status' => 'disetujui', 'sumber' => 'hr',
        ]);
        $d->items()->createMany([
            ['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 150000],
            ['jenis' => 'perawatan', 'penerima' => 'pasangan', 'nominal' => 50000],
        ]);

        $book = $this->book($this->actingAs($this->admin())->get('/comben/deklarasi/export?bulan=2026-10'));

        $this->assertSame(['Rekap Deklarasi', 'Rincian Deklarasi'], $book->getSheetNames());
        $rekap = $this->text($book, 'Rekap Deklarasi');
        $this->assertStringContainsString($k->nrp, $rekap);
        $this->assertStringContainsString('200000', $rekap);
        $this->assertStringContainsString('Istri / Suami', $this->text($book, 'Rincian Deklarasi'));
    }

    public function test_monitoring_export_has_pengobatan_and_kacamata(): void
    {
        $book = $this->book($this->actingAs($this->admin())->get('/comben/monitoring/export?tahun=2026'));

        $this->assertSame(['Plafon Pengobatan 2026', 'Plafon Kacamata 2026'], $book->getSheetNames());
        $this->assertStringContainsString($this->employee()->nrp, $this->text($book, 'Plafon Pengobatan 2026'));
    }

    public function test_cuti_export_adds_cuti_besar_and_rincian(): void
    {
        $k = $this->employee();
        $k->profile()->update(['tanggal_masuk' => '2015-03-01']);
        $k->cutiPengambilans()->create(['jenis' => 'tahunan', 'tahun' => 2026, 'tanggal_awal' => '2026-04-06', 'tanggal_akhir' => '2026-04-08', 'hari' => 3, 'sumber' => 'manual']);

        $book = $this->book($this->actingAs($this->admin())->get('/comben/cuti/export'));

        $this->assertSame(['CUTI TAHUNAN', 'CUTI BESAR', 'RINCIAN PENGAMBILAN'], $book->getSheetNames());
        // Ten years in: two Cuti Besar periods.
        $this->assertSame(2, substr_count($this->text($book, 'CUTI BESAR'), $k->nrp));
        $this->assertStringContainsString('Cuti 2025 · 3 hr', $this->text($book, 'RINCIAN PENGAMBILAN'));
    }

    public function test_trip_export_takes_the_trips_touching_the_months(): void
    {
        $k = $this->employee();
        $base = ['karyawan_id' => $k->id, 'kota_asal' => 'Balikpapan', 'status' => 'disetujui', 'sumber' => 'hr'];
        Trip::create([...$base, 'jenis' => 'dinas', 'tanggal_awal' => '2026-09-29', 'tanggal_akhir' => '2026-10-02', 'kota_tujuan' => 'Jakarta', 'uang_muka' => true, 'uang_muka_nominal' => 2500000]);
        Trip::create([...$base, 'jenis' => 'cuti', 'tanggal_awal' => '2026-12-01', 'tanggal_akhir' => '2026-12-03', 'kota_tujuan' => 'Surabaya']);

        $book = $this->book($this->actingAs($this->admin())->get('/attendance/trip-leave/export?dari=2026-10&sampai=2026-10&jenis=semua'));
        $text = $this->text($book, 'Trip & Leave');

        $this->assertStringContainsString('Jakarta', $text);
        $this->assertStringContainsString('2500000', $text);
        $this->assertStringNotContainsString('Surabaya', $text);
    }

    public function test_exports_follow_the_menu_access(): void
    {
        $karyawan = $this->employee();
        $this->actingAs($this->admin())->post('/settings/akun-karyawan', ['karyawan_ids' => [$karyawan->id]]);
        $portal = User::where('karyawan_id', $karyawan->id)->firstOrFail();

        foreach (['/comben/deklarasi/export', '/comben/monitoring/export', '/comben/cuti/export', '/attendance/trip-leave/export'] as $url) {
            $this->actingAs($portal)->get($url)->assertForbidden();
        }
    }

    public function test_mass_reset_puts_every_ticked_account_back_to_the_first_password(): void
    {
        $people = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->orderBy('id')->take(3)->get();
        $this->actingAs($this->admin())->post('/settings/akun-karyawan', ['karyawan_ids' => $people->pluck('id')->all()])->assertSessionHasNoErrors();
        $accounts = User::whereIn('karyawan_id', $people->pluck('id'))->get()->keyBy('karyawan_id');
        User::whereIn('id', $accounts->pluck('id'))->update(['password' => Hash::make('sudah-diganti-123'), 'must_change_password' => false]);

        // Two of the three ticked; an admin id in the list is ignored.
        $adminHash = $this->admin()->password;
        $ticked = [$accounts[$people[0]->id]->id, $accounts[$people[1]->id]->id, $this->admin()->id];
        $this->actingAs($this->admin())->post('/settings/akun-karyawan/reset-masal', ['user_ids' => $ticked])->assertSessionHasNoErrors();

        foreach ([0, 1] as $i) {
            $u = $accounts[$people[$i]->id]->fresh();
            $this->assertTrue(Hash::check(UserController::initialPassword($people[$i]), $u->password));
            $this->assertTrue($u->must_change_password);
        }
        $untouched = $accounts[$people[2]->id]->fresh();
        $this->assertTrue(Hash::check('sudah-diganti-123', $untouched->password));
        $this->assertFalse($untouched->must_change_password);
        $this->assertSame($adminHash, $this->admin()->fresh()->password);
    }

    public function test_mass_reset_is_for_the_super_admin_only(): void
    {
        $hr = User::where('role', User::ROLE_HR)->firstOrFail();
        $this->actingAs($hr)->post('/settings/akun-karyawan/reset-masal', ['user_ids' => [1]])->assertForbidden();
    }
}
