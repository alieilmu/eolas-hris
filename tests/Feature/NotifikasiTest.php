<?php

namespace Tests\Feature;

use App\Models\Deklarasi;
use App\Models\Karyawan;
use App\Models\ShiftCode;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Access\Menu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-08 09:00:00');
        CarbonImmutable::setTestNow('2026-10-08 09:00:00');
        $this->seed();
        foreach (['cuti' => 'CT', 'dinas' => 'DN'] as $jenis => $kode) {
            if (! ShiftCode::forJenis($jenis)) {
                ShiftCode::create(['kode' => $kode, 'dws' => strtoupper($jenis), 'keterangan' => ucfirst($jenis), 'jenis' => $jenis]);
            }
        }
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

    private function hr(): User
    {
        return User::where('email', 'hr@hrpuspita.local')->firstOrFail();
    }

    /** An employee with a portal account. */
    private function employee(): array
    {
        $k = Karyawan::where('status', Karyawan::STATUS_AKTIF)->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->post('/settings/akun-karyawan', ['karyawan_ids' => [$k->id]]);

        return [$k, tap(User::where('karyawan_id', $k->id)->firstOrFail())->update(['must_change_password' => false])];
    }

    private function hrWith(array $permissions, array $locations = []): User
    {
        return User::create([
            'name' => 'Admin '.count(User::all()), 'email' => uniqid('a').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => $permissions, 'work_location_ids' => $locations, 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function trip(User $user, array $data): void
    {
        $this->actingAs($user)->post('/portal/cuti-dinas', [
            'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Jakarta', 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-22',
            'lampiran' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')], ...$data,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_pengajuan_is_saved_even_when_notifications_cannot_be_written(): void
    {
        // A server where the notifications table is not migrated yet.
        [, $user] = $this->employee();
        Schema::drop('notifications');

        $this->actingAs($user)->post('/portal/deklarasi', [
            'items' => [['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 150000]],
            'lampiran' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $d = Deklarasi::firstOrFail();
        $this->assertSame('diajukan', $d->status);

        // HR still opens its pages and decides.
        $this->actingAs($this->admin())->get('/comben/deklarasi')->assertOk();
        $this->post("/comben/deklarasi/{$d->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();
        $this->assertSame('disetujui', $d->fresh()->status);
    }

    public function test_a_deklarasi_from_the_portal_reaches_the_admins_who_verify_it(): void
    {
        [$k, $user] = $this->employee();
        $viewOnly = $this->hrWith(['deklarasi' => Menu::VIEW]);
        $otherSite = $this->hrWith(['deklarasi' => Menu::EDIT], [WorkLocation::where('id', '!=', $k->work_location_id)->value('id') ?? 999]);
        $sameSite = $this->hrWith(['deklarasi' => Menu::EDIT], [$k->work_location_id]);

        $this->actingAs($user)->post('/portal/deklarasi', [
            'tanggal' => '2026-10-05', 'items' => [['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 150000]],
            'lampiran' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors();
        $d = Deklarasi::firstOrFail();

        foreach ([$this->admin(), $this->hr(), $sameSite] as $to) {
            $n = $to->unreadNotifications()->firstOrFail();
            $this->assertSame('deklarasi', $n->data['jenis']);
            $this->assertSame("deklarasi:{$d->id}", $n->ref);
            $this->assertStringContainsString("{$k->name} ({$k->nrp}) {$d->nomor} · Rp 150.000", $n->data['isi']);
        }
        // "lihat" only, or another site: nothing.
        $this->assertSame(0, $viewOnly->notifications()->count());
        $this->assertSame(0, $otherSite->notifications()->count());
        $this->assertSame(0, $user->notifications()->count());

        // The bell: the count on every page, the list, and the pop-up check.
        $this->actingAs($this->hr())->get('/comben/deklarasi')->assertInertia(fn (Assert $page) => $page->where('auth.notifikasi', 1));
        $this->getJson('/notifikasi')->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('items.0.judul', 'Deklarasi Pengobatan')
            ->assertJsonPath('items.0.url', '/comben/deklarasi?status=diajukan');
        $this->getJson('/notifikasi/jumlah')->assertJsonPath('terbaru.jenis', 'deklarasi');

        // HR approves: it is done for everyone.
        $this->post("/comben/deklarasi/{$d->id}/verifikasi", ['keputusan' => 'setujui'])->assertSessionHasNoErrors();
        $this->assertSame(0, DatabaseNotification::whereNull('read_at')->count());
    }

    public function test_dinas_and_cuti_go_to_pengajuan_dinas_and_close_when_withdrawn(): void
    {
        [$k, $user] = $this->employee();
        $comben = $this->hrWith(['deklarasi' => Menu::EDIT]);

        $this->trip($user, ['jenis' => 'dinas', 'uang_muka' => true, 'uang_muka_nominal' => 2500000]);
        $this->trip($user, ['jenis' => 'cuti', 'kategori_cuti' => 'lainnya', 'tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-04', 'kota_tujuan' => 'Yogyakarta']);

        $items = $this->hr()->notifications()->oldest()->get();
        $this->assertSame(['dinas', 'cuti'], $items->pluck('data.jenis')->all());
        $this->assertStringContainsString('ke Jakarta, 20.10.2026 – 22.10.2026 · uang muka Rp 2.500.000', $items[0]->data['isi']);
        $this->assertStringContainsString('3 hari, 02.11.2026 – 04.11.2026', $items[1]->data['isi']);
        $this->assertSame('/attendance/pengajuan-dinas?jenis=cuti&status=diajukan', $items[1]->data['url']);
        $this->assertSame(0, $comben->notifications()->count());

        // The employee withdraws the cuti: its notification is closed, the dinas one is not.
        $cuti = Trip::where('jenis', 'cuti')->firstOrFail();
        $this->actingAs($user)->delete("/portal/pengajuan/trip/{$cuti->id}")->assertSessionHasNoErrors();
        $this->assertSame(['dinas'], $this->hr()->unreadNotifications()->get()->pluck('data.jenis')->all());
    }

    public function test_update_data_notifies_and_each_admin_reads_their_own(): void
    {
        [$k, $user] = $this->employee();
        $this->actingAs($user)->post('/portal/data', ['no_hp' => '081234567890'])->assertSessionHasNoErrors();

        $mine = $this->hr()->unreadNotifications()->firstOrFail();
        $this->assertSame('Pengajuan Update Data', $mine->data['judul']);
        $theirs = $this->admin()->unreadNotifications()->firstOrFail();

        // Someone else's notification cannot be touched.
        $this->actingAs($this->hr())->postJson("/notifikasi/{$theirs->id}/baca")->assertJsonPath('unread', 1);
        $this->postJson("/notifikasi/{$mine->id}/baca")->assertJsonPath('unread', 0);
        $this->assertNull($theirs->fresh()->read_at);

        $this->actingAs($this->admin())->postJson('/notifikasi/baca-semua')->assertJsonPath('unread', 0);
        $this->assertNotNull($theirs->fresh()->read_at);
    }
}
