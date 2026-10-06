<?php

namespace Tests\Feature;

use App\Models\CutiAturan;
use App\Models\CutiBesar;
use App\Models\CutiLapangan;
use App\Models\Karyawan;
use App\Models\ShiftCode;
use App\Models\SubLocation;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Cuti\Cuti;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CutiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
        $this->seed();
        if (! ShiftCode::forJenis('cuti')) {
            ShiftCode::create(['kode' => 'CT', 'dws' => 'CUTI', 'keterangan' => 'Cuti', 'jenis' => 'cuti']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@hrpuspita.local')->firstOrFail();
    }

    private function employee(string $masuk): Karyawan
    {
        $k = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->whereNotNull('work_location_id')->firstOrFail();
        $k->profile->update(['tanggal_masuk' => $masuk]);

        return $k->fresh('profile');
    }

    private function cuti(Karyawan $k, string $kategori, string $from, string $to): TestResponse
    {
        return $this->post('/attendance/trip-leave', [
            'jenis' => 'cuti', 'kategori_cuti' => $kategori, 'karyawan_id' => $k->id, 'tanggal_awal' => $from, 'tanggal_akhir' => $to,
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Yogyakarta', 'lampiran' => [UploadedFile::fake()->create('cuti.pdf', 20, 'application/pdf')],
        ]);
    }

    public function test_the_annual_balance_follows_the_old_ledger(): void
    {
        // Values of the old LEDGER CUTI sheet on 08.07.2026.
        $at = CarbonImmutable::parse('2026-07-08');
        $t = Cuti::tahunan(CarbonImmutable::parse('2019-05-06'), [2023 => 9, 2024 => 14, 2025 => 5, 2026 => 2], 0, $at);
        $this->assertSame([22, 0], [$t['saldo'], $t['hutang']]);

        // Taken beyond the balance: hutang, paid by the next quota.
        $t = Cuti::tahunan(CarbonImmutable::parse('2023-01-10'), [2024 => 15], 0, $at);
        $this->assertSame([[2024, 12, 0, 3], [2025, 12, 9, 0], [2026, 12, 21, 0]], array_map(fn ($y) => [$y['tahun'], $y['jatah'], $y['sisa'], $y['hutang']], $t['tahun']));

        // A quota lives two years: what is left of 2024 is gone in 2026.
        $t = Cuti::tahunan(CarbonImmutable::parse('2023-01-10'), [], 0, $at);
        $this->assertSame(24, $t['saldo']);
        $this->assertSame(12, $t['hangus_akhir_tahun']);
        // Not reached yet this year: the anniversary has not come.
        $this->assertSame(0, Cuti::tahunan(CarbonImmutable::parse('2025-09-01'), [], 0, $at)['saldo']);
    }

    public function test_field_leave_takes_only_what_goes_beyond_its_allowance(): void
    {
        $k = $this->employee('2018-02-01');
        CutiLapangan::create(['work_location_id' => $k->work_location_id, 'hari_cuti' => 14, 'hari_berangkat' => 2, 'hari_kembali' => 2]);
        $this->actingAs($this->hr());

        $this->cuti($k, '', '2026-10-10', '2026-10-12')->assertSessionHasErrors(['kategori_cuti' => 'Pilih jenis cuti.']);
        $before = Cuti::summary($k)['tahunan']['saldo'];

        // 20 days of cuti lapangan: 18 allowed, 2 from the annual quota.
        $this->cuti($k, 'lapangan', '2026-10-10', '2026-10-29')->assertSessionHasNoErrors();
        $trip = Trip::latest('id')->firstOrFail();
        $this->assertSame(['lapangan', 20, 2, 0], [$trip->kategori_cuti, $trip->hari_cuti, $trip->potong_tahunan, $trip->potong_besar]);
        // A cuti tahunan takes all its days.
        $this->cuti($k, 'tahunan', '2026-11-02', '2026-11-04')->assertSessionHasNoErrors();
        $this->assertSame($before - 5, Cuti::summary($k)['tahunan']['saldo']);

        // The page lists it as in the old sheet, and the export downloads.
        $this->get('/comben/cuti')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comben/Cuti')
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $k->id)['tahunan']['saldo'] === $before - 5));
        $this->get("/comben/cuti/karyawan/{$k->id}")->assertOk()->assertJsonPath('lapangan.total', 18)->assertJsonCount(2, 'pengambilan');
        $this->get('/comben/cuti/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Typed by HR (a leave before the app).
        $this->post("/comben/cuti/karyawan/{$k->id}/pengambilan", ['jenis' => 'tahunan', 'tanggal_awal' => '2026-03-02', 'tanggal_akhir' => '2026-03-03', 'hari' => 2])->assertSessionHasNoErrors();
        $this->assertSame($before - 7, Cuti::summary($k)['tahunan']['saldo']);
    }

    public function test_cuti_besar_is_chosen_by_the_employee_and_used_by_a_cuti(): void
    {
        $k = $this->employee('2016-03-01');
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/akun-karyawan', ['karyawan_ids' => [$k->id]]);
        $user = tap(User::where('karyawan_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);

        // 5 years (2021) passed its deadline: cashed in; 10 years (2026) waits for a choice.
        $periode = collect(Cuti::summary($k)['besar']['periode']);
        $this->assertSame(['otomatis_diuangkan', 'belum_dipilih'], $periode->pluck('status')->all());

        $this->actingAs($user)->post('/portal/cuti-besar', ['periode_ke' => 1, 'keputusan' => 'diambil'])->assertSessionHasErrors('keputusan');
        $this->post('/portal/cuti-besar', ['periode_ke' => 2, 'keputusan' => 'diambil'])->assertSessionHasNoErrors();
        $choice = CutiBesar::firstOrFail();
        $this->assertSame('diajukan', $choice->status);

        $this->actingAs($this->hr())->get('/comben/cuti?tab=besar')->assertInertia(fn (Assert $page) => $page->where('menunggu_besar', 1));
        $this->post("/comben/cuti/besar/{$choice->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();

        // 27 days of Cuti Besar: 25 from it, 2 from the annual quota.
        $this->cuti($k, 'besar', '2026-10-12', '2026-11-07')->assertSessionHasNoErrors();
        $trip = Trip::latest('id')->firstOrFail();
        $this->assertSame([25, 2, 2], [$trip->potong_besar, $trip->potong_tahunan, $trip->cuti_besar_periode]);
        $this->assertSame(0, collect(Cuti::summary($k)['besar']['periode'])->firstWhere('periode_ke', 2)['sisa']);
        // None left to take.
        $this->cuti($k, 'besar', '2026-12-01', '2026-12-02')->assertSessionHasErrors('kategori_cuti');
    }

    public function test_master_data_and_access(): void
    {
        $this->actingAs($this->hr());
        [$site, $other] = WorkLocation::orderBy('id')->limit(2)->pluck('id')->all();
        $sub = SubLocation::create(['work_location_id' => $site, 'name' => 'PIT UJI', 'order' => 99]);

        $this->put('/comben/master-data/cuti-aturan', [
            'jatah_tahunan' => 12, 'masa_aktif_tahun' => 2, 'besar_interval_tahun' => 5, 'besar_hari' => 25,
            'besar_gapok_diambil' => 1, 'besar_gapok_diuangkan' => 2, 'besar_batas_bulan' => 6,
        ])->assertSessionHasNoErrors();
        $this->assertSame(6, CutiAturan::query()->value('besar_batas_bulan'));

        // A sub location of another site; then the same one twice.
        $this->post('/comben/master-data/cuti-lapangan', ['work_location_id' => $other, 'sub_location_id' => $sub->id, 'hari_cuti' => 14, 'hari_berangkat' => 2, 'hari_kembali' => 2])
            ->assertSessionHasErrors('sub_location_id');
        $this->post('/comben/master-data/cuti-lapangan', ['work_location_id' => $sub->work_location_id, 'sub_location_id' => $sub->id, 'hari_cuti' => 10, 'hari_berangkat' => 1, 'hari_kembali' => 1])->assertSessionHasNoErrors();
        $this->post('/comben/master-data/cuti-lapangan', ['work_location_id' => $sub->work_location_id, 'sub_location_id' => $sub->id, 'hari_cuti' => 10, 'hari_berangkat' => 1, 'hari_kembali' => 1])->assertSessionHasErrors('work_location_id');

        $viewer = User::where('email', 'viewer@hrpuspita.local')->firstOrFail();
        $this->actingAs($viewer)->get('/comben/cuti')->assertForbidden();
    }
}
