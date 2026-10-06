<?php

namespace Tests\Feature\Settings;

use App\Models\Absensi;
use App\Models\Deklarasi;
use App\Models\Karyawan;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    /** Two work locations that both have active employees. */
    private function twoLocations(): array
    {
        $ids = Karyawan::where('status', Karyawan::STATUS_AKTIF)->select('work_location_id')->groupBy('work_location_id')->pluck('work_location_id')->take(2)->all();
        $this->assertCount(2, $ids, 'the seed should have employees in two locations');

        return $ids;
    }

    private function admin(array $permissions, array $locations = []): User
    {
        $this->actingAs($this->superAdmin())->post('/settings/akun', [
            'name' => 'Admin Site', 'email' => 'site@hrpuspita.local', 'password' => 'rahasia123', 'role' => User::ROLE_HR,
            'permissions' => $permissions, 'work_location_ids' => $locations,
        ])->assertSessionHasNoErrors();

        return tap(User::where('email', 'site@hrpuspita.local')->firstOrFail())->update(['must_change_password' => false]);
    }

    // --- admin accounts ------------------------------------------------------------------------

    public function test_menus_by_view_and_edit(): void
    {
        $admin = $this->admin(['roster' => 'view', 'deklarasi' => 'edit']);
        $this->actingAs($admin);

        $this->get('/attendance/roster')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)
            ->where('auth.menus', ['roster' => 'view', 'deklarasi' => 'edit']));
        $this->get('/attendance/absensi')->assertForbidden();
        $this->get('/manpower/karyawan')->assertForbidden();
        $this->get('/settings/akun')->assertForbidden();

        // A change with only "lihat": refused, back on the page with the reason.
        $this->post('/attendance/roster/simpan', ['changes' => [['karyawan_id' => 1, 'tanggal' => '2026-10-01', 'shift_code_id' => null]]], ['X-Inertia' => 'true'])
            ->assertRedirect()
            ->assertInertiaFlash('error');
        $this->get('/comben/deklarasi')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_edit', true));

        // "/" opens the first menu it may see.
        $this->get('/')->assertRedirect('/attendance/roster');
    }

    public function test_location_scope_narrows_employees_everywhere(): void
    {
        [$mine, $other] = $this->twoLocations();
        $admin = $this->admin(['karyawan' => 'edit', 'roster' => 'edit', 'monitoring-plafon' => 'view', 'org-structure' => 'view'], [$mine]);
        $outsider = Karyawan::where('work_location_id', $other)->where('status', Karyawan::STATUS_AKTIF)->firstOrFail();
        $insider = Karyawan::where('work_location_id', $mine)->where('status', Karyawan::STATUS_AKTIF)->firstOrFail();
        $this->actingAs($admin);

        $this->get('/attendance/roster')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['work_location_id'] === $mine) && collect($rows)->contains('id', $insider->id))
            ->where('locations', fn ($l) => collect($l)->pluck('id')->all() === [$mine]));
        $this->get('/comben/monitoring')->assertInertia(fn (Assert $page) => $page
            ->where('pengobatan', fn ($rows) => ! collect($rows)->contains('id', $outsider->id)));
        $this->get("/manpower/karyawan/{$outsider->id}/profil")->assertNotFound();
        $this->get("/manpower/karyawan/{$insider->id}/profil")->assertOk();
        $this->get("/manpower/org-structure?lokasi={$other}")->assertInertia(fn (Assert $page) => $page->where('location.id', $mine));

        // Codes for someone outside: refused.
        $this->post('/attendance/roster/simpan', ['changes' => [['karyawan_id' => $outsider->id, 'tanggal' => '2026-10-01', 'shift_code_id' => 1]]])
            ->assertSessionHasErrors('changes');

        // A master data page is not narrowed for the Super Admin, and an unscoped admin sees everyone.
        $this->actingAs($this->superAdmin())->get('/attendance/roster')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->contains('id', $outsider->id)));
    }

    public function test_new_accounts_change_their_password_first_and_the_last_super_admin_stays(): void
    {
        $this->actingAs($this->superAdmin())->post('/settings/akun', [
            'name' => 'Admin Baru', 'email' => 'baru@hrpuspita.local', 'password' => 'awal12345', 'role' => User::ROLE_HR, 'permissions' => [],
        ])->assertSessionHasErrors(['permissions' => 'Centang minimal satu menu yang bisa diakses.']);
        $this->post('/settings/akun', [
            'name' => 'Admin Baru', 'email' => 'baru@hrpuspita.local', 'password' => 'awal12345', 'role' => User::ROLE_HR, 'permissions' => ['absensi' => 'edit'],
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => 'baru@hrpuspita.local', 'password' => 'awal12345'])->assertRedirect('/');
        $this->get('/attendance/absensi')->assertRedirect('/akun/password');
        $this->put('/akun/password', ['current_password' => 'awal12345', 'password' => 'baru-saja-1', 'password_confirmation' => 'baru-saja-1'])->assertRedirect('/');
        $this->get('/attendance/absensi')->assertOk();

        $super = $this->superAdmin();
        $this->actingAs($super)->delete("/settings/akun/{$super->id}")->assertSessionHasErrors('user');
        $this->put("/settings/akun/{$super->id}", ['name' => $super->name, 'email' => $super->email, 'role' => User::ROLE_HR, 'permissions' => ['roster' => 'view']])
            ->assertSessionHasErrors('role');
    }

    // --- employee accounts & portal -------------------------------------------------------------

    private function employeeAccount(): array
    {
        $k = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->whereHas('subGroup', fn ($q) => $q->where('nama', 'Operator'))->firstOrFail();
        $k->profile->update(['tanggal_lahir' => '1992-04-10', 'status_perkawinan' => 'Menikah', 'nama_pasangan' => 'Siti', 'golongan' => 'D2J']);
        $this->actingAs($this->superAdmin())->post('/settings/akun-karyawan', ['karyawan_ids' => [$k->id]])->assertSessionHasNoErrors();
        auth()->logout();

        return [$k, User::where('karyawan_id', $k->id)->firstOrFail()];
    }

    public function test_employees_log_in_with_their_nrp_and_birth_date(): void
    {
        [$k, $user] = $this->employeeAccount();
        $this->assertSame(User::ROLE_KARYAWAN, $user->role);

        $this->post('/login', ['email' => $k->nrp, 'password' => 'salah'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $k->nrp, 'password' => '10041992'])->assertRedirect('/');
        $this->get('/portal')->assertRedirect('/akun/password');
        $this->put('/akun/password', ['current_password' => '10041992', 'password' => 'karyawan-1', 'password_confirmation' => 'karyawan-1']);
        $this->get('/')->assertRedirect('/portal');
        $this->get('/portal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Home')
            ->where('karyawan.nrp', $k->nrp)
            ->where('plafon.pengobatan.plafon', 2250000));
        // Nothing of the admin side.
        $this->get('/manpower/karyawan')->assertForbidden();
        $this->get('/comben/deklarasi')->assertForbidden();

        // An employee who left can no longer sign in.
        auth()->logout();
        $k->update(['status' => Karyawan::STATUS_KELUAR, 'tanggal_keluar' => '2026-10-04', 'alasan_keluar' => 'Resign']);
        $this->post('/login', ['email' => $k->nrp, 'password' => 'karyawan-1'])->assertSessionHasErrors(['email' => 'Akun ini tidak aktif. Hubungi HR.']);
    }

    public function test_accounts_for_all_employees_at_once(): void
    {
        $ids = Karyawan::where('status', Karyawan::STATUS_AKTIF)->pluck('id');
        $this->assertGreaterThan(5, $ids->count());
        $k = Karyawan::with('profile')->findOrFail($ids->first());
        $k->profile->update(['tanggal_lahir' => '1990-01-31']);

        $this->actingAs($this->superAdmin())->post('/settings/akun-karyawan', ['karyawan_ids' => $ids->all()])->assertSessionHasNoErrors();
        $this->assertSame($ids->count(), User::where('role', User::ROLE_KARYAWAN)->count());
        // Again: the existing accounts are skipped.
        $this->post('/settings/akun-karyawan', ['karyawan_ids' => $ids->all()])->assertSessionHasNoErrors();
        $this->assertSame($ids->count(), User::where('role', User::ROLE_KARYAWAN)->count());
        auth()->logout();

        $this->post('/login', ['email' => $k->nrp, 'password' => '31011990'])->assertRedirect('/');
        $this->get('/portal')->assertRedirect('/akun/password');
    }

    public function test_portal_pengajuan_wait_for_hr_and_hold_the_plafon(): void
    {
        [$k, $user] = $this->employeeAccount();
        $user->update(['must_change_password' => false]);
        $this->actingAs($user);

        // Deklarasi: waiting, already holding its amount.
        $this->post('/portal/deklarasi', ['tanggal' => '2026-10-05', 'lampiran' => [UploadedFile::fake()->image('nota.jpg')], 'items' => [
            ['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 2000000],
        ]])->assertSessionHasNoErrors();
        $d = Deklarasi::firstOrFail();
        $this->assertSame(['diajukan', 'portal', $k->id], [$d->status, $d->sumber, $d->karyawan_id]);
        $this->getJson('/portal/plafon')->assertJsonPath('pengobatan.menunggu', 2000000)->assertJsonPath('pengobatan.sisa', 250000);
        $this->post('/portal/deklarasi', ['tanggal' => '2026-10-05', 'lampiran' => [UploadedFile::fake()->image('nota.jpg')], 'items' => [
            ['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 300000],
        ]])->assertSessionHasErrors('items');

        // Cuti: waiting, not in Absensi yet.
        $this->post('/portal/cuti-dinas', ['jenis' => 'cuti', 'kategori_cuti' => 'lainnya', 'tanggal_awal' => '2026-10-12', 'tanggal_akhir' => '2026-10-14', 'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Yogyakarta', 'lampiran' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')]])
            ->assertSessionHasNoErrors();
        $trip = Trip::firstOrFail();
        $this->assertSame('diajukan', $trip->status);
        $this->assertSame(0, Absensi::count());

        // HR decides.
        $hr = User::where('email', 'hr@hrpuspita.local')->firstOrFail();
        $this->actingAs($hr)->get('/comben/deklarasi?status=diajukan')->assertInertia(fn (Assert $page) => $page->where('menunggu', 1)->has('deklarasis.data', 1));
        $this->post("/comben/deklarasi/{$d->id}/verifikasi", ['keputusan' => 'tolak'])->assertSessionHasErrors('catatan');
        $this->post("/comben/deklarasi/{$d->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();
        $this->assertSame('disetujui', $d->fresh()->status);
        $this->post("/attendance/trip-leave/{$trip->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();
        $this->assertSame(3, Absensi::where('trip_id', $trip->id)->count());

        // Back as the employee: approved claims are used; a rejected one frees the plafon.
        $this->actingAs($user)->post('/portal/deklarasi', ['tanggal' => '2026-10-05', 'lampiran' => [UploadedFile::fake()->image('nota.jpg')], 'items' => [
            ['jenis' => 'pengobatan', 'penerima' => 'pasangan', 'nominal' => 250000],
        ]])->assertSessionHasNoErrors();
        $second = Deklarasi::latest('id')->firstOrFail();
        $this->actingAs($hr)->post("/comben/deklarasi/{$second->id}/verifikasi", ['keputusan' => 'tolak', 'catatan' => 'Kwitansi tidak terbaca'])->assertSessionHasNoErrors();
        $this->actingAs($user)->getJson('/portal/plafon')->assertJsonPath('pengobatan.terpakai', 2000000)->assertJsonPath('pengobatan.sisa', 250000);

        // Only own, waiting pengajuan can be withdrawn.
        $this->delete("/portal/pengajuan/deklarasi/{$second->id}")->assertNotFound();
        $this->post('/portal/cuti-dinas', ['jenis' => 'dinas', 'tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-03', 'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', 'lampiran' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')]]);
        $this->delete('/portal/pengajuan/trip/'.Trip::latest('id')->value('id'))->assertSessionHasNoErrors();
        $this->assertSame(1, Trip::count());
    }
}
