<?php

namespace Tests\Feature;

use App\Models\Karyawan;
use App\Models\KaryawanAnak;
use App\Models\PengajuanUpdateData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PengajuanUpdateDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
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
        return User::where('email', 'hr@hrpuspita.local')->firstOrFail();
    }

    /** @return array{0: Karyawan, 1: User} */
    private function employee(): array
    {
        $k = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->firstOrFail();
        $k->profile->update(['tanggal_lahir' => '1990-01-31', 'agama' => 'Islam', 'nik' => null, 'status_pegawai' => 'Tetap']);
        $profile = $k->profile;
        $profile->anak()->delete();
        $profile->anak()->create(['nama' => 'ANAK SATU', 'tanggal_lahir' => '2015-03-01', 'urutan' => 1]);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/akun-karyawan', ['karyawan_ids' => [$k->id]]);
        $user = tap(User::where('karyawan_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);

        return [$k->fresh('profile.anak'), $user];
    }

    public function test_an_employee_asks_and_hr_approves(): void
    {
        [$k, $user] = $this->employee();
        $anak = $k->profile->anak->first();
        $this->actingAs($user);

        $this->get('/portal/data')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Data')
            ->where('profile.agama', 'Islam')
            ->has('anak', 1)
            ->where('menunggu', null)
            // HR-only fields are not on the form.
            ->where('sections', fn ($s) => ! collect($s)->pluck('id')->intersect(['identitas', 'golongan', 'pkwt', 'pengangkatan'])->count()
                && ! collect($s)->flatMap(fn ($x) => collect($x['fields'] ?? [])->pluck('key'))->contains('status_pegawai')));

        // Nothing changed.
        $this->post('/portal/data', ['agama' => 'Islam'])->assertSessionHasErrors('perubahan');
        // A wrong NIK.
        $this->post('/portal/data', ['nik' => '123'])->assertSessionHasErrors('nik');

        $this->post('/portal/data', [
            'agama' => 'Islam', 'nik' => '3209011609820001', 'no_hp' => '081234567890', 'status_pegawai' => 'PKWT', 'golongan' => 'D1J',
            'anak' => [
                ['id' => $anak->id, 'nama' => 'ANAK SATU', 'tanggal_lahir' => '2015-03-02'],
                ['nama' => 'ANAK DUA', 'tanggal_lahir' => '2019-07-10', 'pendidikan' => 'TK'],
            ],
            'catatan' => 'KTP baru',
            'lampiran' => [UploadedFile::fake()->image('ktp.jpg')],
        ])->assertSessionHasNoErrors();

        $p = PengajuanUpdateData::firstOrFail();
        $this->assertSame(['nik', 'no_hp'], array_keys($p->perubahan));
        $this->assertSame(['ubah', 'tambah'], array_column($p->anak, 'aksi'));
        $this->assertCount(1, $p->lampirans);
        // Not applied yet, and one request at a time.
        $this->assertNull($k->profile->fresh()->nik);
        $this->post('/portal/data', ['no_hp' => '089999999999'])->assertSessionHasErrors('perubahan');

        // HR sees it and approves.
        $this->actingAs($this->hr())->get('/manpower/pengajuan-data')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Manpower/PengajuanData')
            ->where('counts.diajukan', 1)
            ->where('items.data.0.jumlah', 4)
            ->where('fields.nik.label', 'NIK (KTP)'));
        $this->get("/lampiran/{$p->lampirans[0]->id}")->assertOk();
        $this->post("/manpower/pengajuan-data/{$p->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();

        $profile = $k->profile->fresh('anak');
        $this->assertSame(['3209011609820001', '081234567890', 'Tetap'], [$profile->nik, $profile->no_hp, $profile->status_pegawai]);
        $this->assertSame([['ANAK SATU', '2015-03-02', 1], ['ANAK DUA', '2019-07-10', 2]],
            $profile->anak->map(fn (KaryawanAnak $a) => [$a->nama, $a->tanggal_lahir->toDateString(), $a->urutan])->all());
        $this->assertSame('disetujui', $p->fresh()->status);
    }

    public function test_rejecting_removing_a_child_and_cancelling(): void
    {
        [$k, $user] = $this->employee();
        $anak = $k->profile->anak->first();

        $this->actingAs($user)->post('/portal/data', ['anak' => [['id' => $anak->id, 'nama' => 'ANAK SATU', 'hapus' => true]]])->assertSessionHasNoErrors();
        $p = PengajuanUpdateData::firstOrFail();
        $this->assertSame('hapus', $p->anak[0]['aksi']);

        $this->actingAs($this->hr())->post("/manpower/pengajuan-data/{$p->id}/verifikasi", ['keputusan' => 'tolak'])->assertSessionHasErrors('catatan');
        $this->post("/manpower/pengajuan-data/{$p->id}/verifikasi", ['keputusan' => 'tolak', 'catatan' => 'Lampirkan KK'])->assertSessionHasNoErrors();
        $this->assertSame(1, $k->profile->anak()->count());

        // The employee sees the reason, sends again and withdraws it.
        $this->actingAs($user)->get('/portal/data')->assertInertia(fn (Assert $page) => $page->where('riwayat.0.catatan_verifikasi', 'Lampirkan KK'));
        $this->post('/portal/data', ['suku_bangsa' => 'Jawa'])->assertSessionHasNoErrors();
        $second = PengajuanUpdateData::latest('id')->firstOrFail();
        $this->delete("/portal/data/{$second->id}")->assertSessionHasNoErrors();
        $this->assertNull(PengajuanUpdateData::find($second->id));
    }

    public function test_a_number_taken_since_is_refused_and_only_hr_with_the_menu_decides(): void
    {
        [$k, $user] = $this->employee();
        $this->actingAs($user)->post('/portal/data', ['nik' => '3209011609820001'])->assertSessionHasNoErrors();
        $p = PengajuanUpdateData::firstOrFail();

        // Someone else got that NIK meanwhile.
        Karyawan::withoutGlobalScope('akses')->where('id', '!=', $k->id)->firstOrFail()->profile()->update(['nik' => '3209011609820001']);
        $this->actingAs($this->hr())->post("/manpower/pengajuan-data/{$p->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasErrors('keputusan');
        $this->assertSame('diajukan', $p->fresh()->status);

        // An admin without the menu; the employee on the HR page.
        $viewer = User::where('email', 'viewer@hrpuspita.local')->firstOrFail();
        $this->actingAs($viewer)->get('/manpower/pengajuan-data')->assertForbidden();
        $this->actingAs($user)->get('/manpower/pengajuan-data')->assertForbidden();
    }
}
