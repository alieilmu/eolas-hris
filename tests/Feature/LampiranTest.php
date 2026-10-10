<?php

namespace Tests\Feature;

use App\Models\Deklarasi;
use App\Models\Karyawan;
use App\Models\Lampiran;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LampiranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-05 09:00:00');
        CarbonImmutable::setTestNow('2026-10-05 09:00:00');
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@eloas.local')->firstOrFail();
    }

    private function employee(): Karyawan
    {
        $k = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->whereHas('subGroup', fn ($q) => $q->where('nama', 'Operator'))->firstOrFail();
        $k->profile->update(['tanggal_lahir' => '1992-04-10', 'status_perkawinan' => 'Menikah', 'nama_pasangan' => 'Siti', 'golongan' => 'D2J']);

        return $k;
    }

    private function portalUser(Karyawan $k): User
    {
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/akun-karyawan', ['karyawan_ids' => [$k->id]]);

        return tap(User::where('karyawan_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);
    }

    private function claim(Karyawan $k, array $extra = []): array
    {
        return ['karyawan_id' => $k->id, 'tanggal' => '2026-10-02', 'items' => [['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 150000]], ...$extra];
    }

    private function pdf(string $name = 'nota.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    public function test_a_deklarasi_needs_its_nota_and_counts_them(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());

        $this->post('/comben/deklarasi', $this->claim($k))->assertSessionHasErrors(['lampiran' => 'Lampirkan foto / PDF nota (minimal satu).']);
        $this->post('/comben/deklarasi', $this->claim($k, ['lampiran' => [UploadedFile::fake()->create('nota.docx', 10)]]))->assertSessionHasErrors('lampiran.0');

        // Two photos and a PDF of three pages: five nota.
        $this->post('/comben/deklarasi', $this->claim($k, [
            'lampiran' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), $this->pdf()],
            'halaman' => [null, null, 3],
        ]))->assertSessionHasNoErrors();
        $d = Deklarasi::with('lampirans')->firstOrFail();
        $this->assertSame(5, $d->jumlah_kwitansi);
        $this->assertCount(3, $d->lampirans);
        Storage::disk('local')->assertExists($d->lampirans[0]->path);

        // Taking one photo out and adding none: four nota; taking all out is refused.
        $ids = $d->lampirans->pluck('id');
        $this->post("/comben/deklarasi/{$d->id}", [...$this->claim($k), '_method' => 'put', 'hapus_lampiran' => [$ids[0]]])->assertSessionHasNoErrors();
        $this->assertSame(4, $d->fresh()->jumlah_kwitansi);
        Storage::disk('local')->assertMissing($d->lampirans[0]->path);
        $this->post("/comben/deklarasi/{$d->id}", [...$this->claim($k), '_method' => 'put', 'hapus_lampiran' => $ids->all()])->assertSessionHasErrors('lampiran');

        // Deleting the deklarasi deletes its files.
        $path = $d->lampirans[2]->path;
        $this->delete("/comben/deklarasi/{$d->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Lampiran::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_only_the_employee_and_admins_of_the_menu_open_a_lampiran(): void
    {
        $k = $this->employee();
        $user = $this->portalUser($k);
        $this->actingAs($user)->post('/portal/deklarasi', [
            'tanggal' => '2026-10-05', 'items' => [['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 100000]],
            'lampiran' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors();
        $lampiran = Lampiran::firstOrFail();

        $this->get("/lampiran/{$lampiran->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->hr())->get("/lampiran/{$lampiran->id}")->assertOk();

        // Another employee: not theirs.
        $other = Karyawan::where('status', Karyawan::STATUS_AKTIF)->where('id', '!=', $k->id)->firstOrFail();
        $this->actingAs($this->portalUser($other))->get("/lampiran/{$lampiran->id}")->assertNotFound();
        // An admin without Deklarasi / Monitoring.
        $viewer = User::where('email', 'viewer@eloas.local')->firstOrFail();
        $this->actingAs($viewer)->get("/lampiran/{$lampiran->id}")->assertForbidden();
    }

    public function test_cuti_and_dinas_need_the_signed_form_and_can_ask_for_tickets(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());
        $trip = fn (array $extra) => $this->post('/attendance/trip-leave', [
            'jenis' => 'dinas', 'karyawan_id' => $k->id, 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-22',
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', ...$extra,
        ]);

        $trip([])->assertSessionHasErrors(['lampiran' => 'Lampirkan foto / PDF form dinas yang sudah disetujui.']);
        $trip(['lampiran' => [$this->pdf('st.pdf')], 'tiket_pesawat' => '1'])->assertSessionHasErrors(['bandara_asal', 'bandara_tujuan']);
        $trip(['lampiran' => [$this->pdf('st.pdf')], 'tiket_pesawat' => '1', 'bandara_asal' => 'TJG', 'bandara_tujuan' => 'TJG'])->assertSessionHasErrors('bandara_tujuan');
        $trip(['lampiran' => [$this->pdf('st.pdf')], 'tiket_pesawat' => '1', 'bandara_asal' => 'XXX', 'bandara_tujuan' => 'CGK'])->assertSessionHasErrors('bandara_asal');

        $trip(['lampiran' => [$this->pdf('st.pdf')], 'tiket_pesawat' => '1', 'bandara_asal' => 'BDJ', 'bandara_tujuan' => 'CGK', 'pesawat_pp' => '1',
            'pulang_tanggal' => '2026-10-22', 'pulang_bandara_asal' => 'CGK', 'pulang_bandara_tujuan' => 'BDJ', 'tiket_kereta' => '1', 'keterangan' => 'Gambir → Bandung'])
            ->assertSessionHasNoErrors();
        $t = Trip::with('lampirans')->firstOrFail();
        $this->assertSame([true, 'BDJ', 'CGK', true, true], [$t->tiket_pesawat, $t->bandara_asal, $t->bandara_tujuan, $t->pesawat_pp, $t->tiket_kereta]);
        $this->assertCount(1, $t->lampirans);

        // Cuti: no train ticket; no flight clears the airports.
        $this->put("/attendance/trip-leave/{$t->id}", [
            'jenis' => 'cuti', 'kategori_cuti' => 'lainnya', 'karyawan_id' => $k->id, 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-22',
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', 'tiket_pesawat' => '0', 'bandara_asal' => 'BDJ', 'tiket_kereta' => '1',
        ])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame([false, null, null, false, false], [$t->tiket_pesawat, $t->bandara_asal, $t->bandara_tujuan, $t->pesawat_pp, $t->tiket_kereta]);
    }

    public function test_a_round_trip_flight_a_train_and_uang_muka(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());
        $trip = fn (array $extra) => $this->post('/attendance/trip-leave', [
            'jenis' => 'dinas', 'karyawan_id' => $k->id, 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-22',
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', 'lampiran' => [$this->pdf('st.pdf')],
            'tiket_pesawat' => '1', 'bandara_asal' => 'TJG', 'bandara_tujuan' => 'CGK', ...$extra,
        ]);

        // PP: the way back is asked for.
        $trip(['pesawat_pp' => '1'])->assertSessionHasErrors(['pulang_tanggal', 'pulang_bandara_asal', 'pulang_bandara_tujuan']);
        $trip(['pesawat_pp' => '1', 'pulang_tanggal' => '2026-10-19', 'pulang_bandara_asal' => 'CGK', 'pulang_bandara_tujuan' => 'CGK'])
            ->assertSessionHasErrors(['pulang_tanggal', 'pulang_bandara_tujuan']);
        // Train: the stations go in Keterangan. Uang muka: its amount.
        $trip(['tiket_kereta' => '1'])->assertSessionHasErrors('keterangan');
        $trip(['uang_muka' => '1'])->assertSessionHasErrors(['uang_muka_nominal' => 'Isi jumlah uang muka yang diajukan.']);

        $trip([
            'pesawat_pp' => '1', 'pulang_tanggal' => '2026-10-22', 'pulang_bandara_asal' => 'CGK', 'pulang_bandara_tujuan' => 'BDJ',
            'tiket_kereta' => '1', 'keterangan' => 'Gambir → Bandung', 'uang_muka' => '1', 'uang_muka_nominal' => '2500000',
        ])->assertSessionHasNoErrors();
        $t = Trip::firstOrFail();
        $this->assertSame(['2026-10-22', 'CGK', 'BDJ'], [$t->pulang_tanggal->toDateString(), $t->pulang_bandara_asal, $t->pulang_bandara_tujuan]);
        $this->assertSame([true, 2500000, 'Diajukan'], [$t->uang_muka, $t->uang_muka_nominal, $t->status_uang]);

        // As cuti: no train, no uang muka; without PP the way back is cleared.
        $this->put("/attendance/trip-leave/{$t->id}", [
            'jenis' => 'cuti', 'kategori_cuti' => 'lainnya', 'karyawan_id' => $k->id, 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-22',
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', 'tiket_pesawat' => '1', 'bandara_asal' => 'TJG', 'bandara_tujuan' => 'CGK',
            'pesawat_pp' => '0', 'pulang_tanggal' => '2026-10-22', 'tiket_kereta' => '1', 'uang_muka' => '1', 'uang_muka_nominal' => '100',
        ])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame([false, null, false, false, null], [$t->pesawat_pp, $t->pulang_tanggal, $t->tiket_kereta, $t->uang_muka, $t->uang_muka_nominal]);
    }
}
