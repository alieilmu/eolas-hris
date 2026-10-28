<?php

namespace Tests\Feature;

use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
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

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-08 09:00:00');
        CarbonImmutable::setTestNow('2026-10-08 09:00:00');
        $this->seed();
        foreach (['leave' => 'CT', 'business_trip' => 'DN'] as $type => $code) {
            if (! ShiftCode::forType($type)) {
                ShiftCode::create(['code' => $code, 'daily_work_schedule' => strtoupper($type), 'description' => ucfirst($type), 'type' => $type]);
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
        return User::where('email', 'hr@eolas.local')->firstOrFail();
    }

    /** An employee with a portal account. */
    private function employee(): array
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->post('/settings/employee-accounts', ['employee_ids' => [$k->id]]);

        return [$k, tap(User::where('employee_id', $k->id)->firstOrFail())->update(['must_change_password' => false])];
    }

    private function hrWith(array $permissions, array $locations = []): User
    {
        return User::create([
            'name' => 'Admin '.count(User::all()), 'email' => uniqid('a').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => $permissions, 'work_location_ids' => $locations, 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function leave_travel(User $user, array $data): void
    {
        $this->actingAs($user)->post('/self-service/leave-travel', [
            'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
            'attachment' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')], ...$data,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_pengajuan_is_saved_even_when_notifications_cannot_be_written(): void
    {
        // A server where the notifications table is not migrated yet.
        [, $user] = $this->employee();
        Schema::drop('notifications');

        $this->actingAs($user)->post('/self-service/claim', [
            'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000]],
            'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $d = BenefitClaim::firstOrFail();
        $this->assertSame('pending', $d->status);

        // HR still opens its pages and decides.
        $this->actingAs($this->admin())->get('/compensation/claims')->assertOk();
        $this->post("/compensation/claims/{$d->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $d->fresh()->status);
    }

    public function test_a_deklarasi_from_the_portal_reaches_the_admins_who_verify_it(): void
    {
        [$k, $user] = $this->employee();
        $viewOnly = $this->hrWith(['benefit-claims' => Menu::VIEW]);
        $otherSite = $this->hrWith(['benefit-claims' => Menu::EDIT], [WorkLocation::where('id', '!=', $k->work_location_id)->value('id') ?? 999]);
        $sameSite = $this->hrWith(['benefit-claims' => Menu::EDIT], [$k->work_location_id]);

        $this->actingAs($user)->post('/self-service/claim', [
            'date' => '2026-10-05', 'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000]],
            'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')],
        ])->assertSessionHasNoErrors();
        $d = BenefitClaim::firstOrFail();

        foreach ([$this->admin(), $this->hr(), $sameSite] as $to) {
            $n = $to->unreadNotifications()->firstOrFail();
            $this->assertSame('claim', $n->data['type']);
            $this->assertSame("claim:{$d->id}", $n->ref);
            $this->assertStringContainsString("{$k->name} ({$k->employee_number}) {$d->claim_number} · Rp 150.000", $n->data['body']);
        }
        // "lihat" only, or another site: nothing.
        $this->assertSame(0, $viewOnly->notifications()->count());
        $this->assertSame(0, $otherSite->notifications()->count());
        $this->assertSame(0, $user->notifications()->count());

        // The bell: the count on every page, the list, and the pop-up check.
        $this->actingAs($this->hr())->get('/compensation/claims')->assertInertia(fn (Assert $page) => $page->where('auth.notifications', 1));
        $this->getJson('/notifications')->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('items.0.title', 'Deklarasi Pengobatan')
            ->assertJsonPath('items.0.url', '/compensation/claims?status=pending');
        $this->getJson('/notifications/quantity')->assertJsonPath('latest.type', 'claim');

        // HR approves: it is done for everyone; the employee hears the result.
        $this->post("/compensation/claims/{$d->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(0, DatabaseNotification::where('ref', "claim:{$d->id}")->whereNull('read_at')->count());
        $this->assertSame('Deklarasi Benefit disetujui', $user->unreadNotifications()->sole()->data['title']);
    }

    public function test_dinas_and_cuti_go_to_pengajuan_dinas_and_close_when_withdrawn(): void
    {
        [$k, $user] = $this->employee();
        $compensation = $this->hrWith(['benefit-claims' => Menu::EDIT]);

        $this->leave_travel($user, ['type' => 'business_trip', 'needs_cash_advance' => true, 'cash_advance_amount' => 2500000]);
        $this->leave_travel($user, ['type' => 'leave', 'leave_category' => 'other', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'destination_city' => 'Yogyakarta']);

        $items = $this->hr()->notifications()->oldest()->get();
        $this->assertSame(['business_trip', 'leave'], $items->pluck('data.type')->all());
        $this->assertStringContainsString('ke Jakarta, 20.10.2026 – 22.10.2026 · uang muka Rp 2.500.000', $items[0]->data['body']);
        $this->assertStringContainsString('3 hari, 02.11.2026 – 04.11.2026', $items[1]->data['body']);
        $this->assertSame('/time/travel-requests?type=leave&status=pending', $items[1]->data['url']);
        $this->assertSame(0, $compensation->notifications()->count());

        // The employee withdraws the cuti: its notification is closed, the dinas one is not.
        $leave = LeaveTravelRequest::where('type', 'leave')->firstOrFail();
        $this->actingAs($user)->delete("/self-service/submission/leave-travel/{$leave->id}")->assertSessionHasNoErrors();
        $this->assertSame(['business_trip'], $this->hr()->unreadNotifications()->get()->pluck('data.type')->all());
    }

    public function test_update_data_notifies_and_each_admin_reads_their_own(): void
    {
        [$k, $user] = $this->employee();
        $this->actingAs($user)->post('/self-service/profile', ['phone' => '081234567890'])->assertSessionHasNoErrors();

        $mine = $this->hr()->unreadNotifications()->firstOrFail();
        $this->assertSame('Pengajuan Update Data', $mine->data['title']);
        $theirs = $this->admin()->unreadNotifications()->firstOrFail();

        // Someone else's notification cannot be touched.
        $this->actingAs($this->hr())->postJson("/notifications/{$theirs->id}/read")->assertJsonPath('unread', 1);
        $this->postJson("/notifications/{$mine->id}/read")->assertJsonPath('unread', 0);
        $this->assertNull($theirs->fresh()->read_at);

        $this->actingAs($this->admin())->postJson('/notifications/read-all')->assertJsonPath('unread', 0);
        $this->assertNotNull($theirs->fresh()->read_at);
    }
}
