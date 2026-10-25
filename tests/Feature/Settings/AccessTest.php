<?php

namespace Tests\Feature\Settings;

use App\Models\AttendanceRecord;
use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
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
        $ids = Employee::where('status', Employee::STATUS_ACTIVE)->select('work_location_id')->groupBy('work_location_id')->pluck('work_location_id')->take(2)->all();
        $this->assertCount(2, $ids, 'the seed should have employees in two locations');

        return $ids;
    }

    private function admin(array $permissions, array $locations = []): User
    {
        $this->actingAs($this->superAdmin())->post('/settings/users', [
            'name' => 'Admin Site', 'email' => 'site@eolas.local', 'password' => 'rahasia123', 'role' => User::ROLE_HR,
            'permissions' => $permissions, 'work_location_ids' => $locations,
        ])->assertSessionHasNoErrors();

        return tap(User::where('email', 'site@eolas.local')->firstOrFail())->update(['must_change_password' => false]);
    }

    // --- admin accounts ------------------------------------------------------------------------

    public function test_menus_by_view_and_edit(): void
    {
        $admin = $this->admin(['shift-schedule' => 'view', 'benefit-claims' => 'edit']);
        $this->actingAs($admin);

        $this->get('/time/shift-schedule')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_edit', false)
            ->where('auth.menus', ['shift-schedule' => 'view', 'benefit-claims' => 'edit']));
        $this->get('/time/attendance')->assertForbidden();
        $this->get('/core-hr/employees')->assertForbidden();
        $this->get('/settings/users')->assertForbidden();

        // A change with only "lihat": refused, back on the page with the reason.
        $this->post('/time/shift-schedule/save', ['changes' => [['employee_id' => 1, 'date' => '2026-10-01', 'shift_code_id' => null]]], ['X-Inertia' => 'true'])
            ->assertRedirect()
            ->assertInertiaFlash('error');
        $this->get('/compensation/claims')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_edit', true));

        // "/" opens the dashboard (an overview of what the account may see).
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_location_scope_narrows_employees_everywhere(): void
    {
        [$mine, $other] = $this->twoLocations();
        $admin = $this->admin(['employees' => 'edit', 'shift-schedule' => 'edit', 'benefit-balances' => 'view', 'org-chart' => 'view'], [$mine]);
        $outsider = Employee::where('work_location_id', $other)->where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $insider = Employee::where('work_location_id', $mine)->where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $this->actingAs($admin);

        $this->get('/time/shift-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['work_location_id'] === $mine) && collect($rows)->contains('id', $insider->id))
            ->where('locations', fn ($l) => collect($l)->pluck('id')->all() === [$mine]));
        $this->get('/compensation/benefit-balances')->assertInertia(fn (Assert $page) => $page
            ->where('medical', fn ($rows) => ! collect($rows)->contains('id', $outsider->id)));
        $this->get("/core-hr/employees/{$outsider->id}/profile")->assertNotFound();
        $this->get("/core-hr/employees/{$insider->id}/profile")->assertOk();
        $this->get("/core-hr/org-chart?location={$other}")->assertInertia(fn (Assert $page) => $page->where('location.id', $mine));

        // Codes for someone outside: refused.
        $this->post('/time/shift-schedule/save', ['changes' => [['employee_id' => $outsider->id, 'date' => '2026-10-01', 'shift_code_id' => 1]]])
            ->assertSessionHasErrors('changes');

        // A master data page is not narrowed for the Super Admin, and an unscoped admin sees everyone.
        $this->actingAs($this->superAdmin())->get('/time/shift-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->contains('id', $outsider->id)));
    }

    public function test_new_accounts_change_their_password_first_and_the_last_super_admin_stays(): void
    {
        $this->actingAs($this->superAdmin())->post('/settings/users', [
            'name' => 'Admin Baru', 'email' => 'new@eolas.local', 'password' => 'awal12345', 'role' => User::ROLE_HR, 'permissions' => [],
        ])->assertSessionHasErrors(['permissions' => 'Centang minimal satu menu yang bisa diakses.']);
        $this->post('/settings/users', [
            'name' => 'Admin Baru', 'email' => 'new@eolas.local', 'password' => 'awal12345', 'role' => User::ROLE_HR, 'permissions' => ['attendance-records' => 'edit'],
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => 'new@eolas.local', 'password' => 'awal12345'])->assertRedirect('/');
        $this->get('/time/attendance')->assertRedirect('/account/password');
        $this->put('/account/password', ['current_password' => 'awal12345', 'password' => 'new-saja-1', 'password_confirmation' => 'new-saja-1'])->assertRedirect('/');
        $this->get('/time/attendance')->assertOk();

        $super = $this->superAdmin();
        $this->actingAs($super)->delete("/settings/users/{$super->id}")->assertSessionHasErrors('user');
        $this->put("/settings/users/{$super->id}", ['name' => $super->name, 'email' => $super->email, 'role' => User::ROLE_HR, 'permissions' => ['shift-schedule' => 'view']])
            ->assertSessionHasErrors('role');
    }

    // --- employee accounts & portal -------------------------------------------------------------

    private function employeeAccount(): array
    {
        $k = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->whereHas('jobLevel', fn ($q) => $q->where('name', 'Operator'))->firstOrFail();
        $k->profile->update(['birth_date' => '1992-04-10', 'marital_status' => 'Menikah', 'spouse_name' => 'Siti', 'grade' => 'D2J']);
        $this->actingAs($this->superAdmin())->post('/settings/employee-accounts', ['employee_ids' => [$k->id]])->assertSessionHasNoErrors();
        auth()->logout();

        return [$k, User::where('employee_id', $k->id)->firstOrFail()];
    }

    public function test_employees_log_in_with_their_nrp_and_birth_date(): void
    {
        [$k, $user] = $this->employeeAccount();
        $this->assertSame(User::ROLE_EMPLOYEE, $user->role);

        $this->post('/login', ['email' => $k->employee_number, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $k->employee_number, 'password' => '10041992'])->assertRedirect('/');
        $this->get('/self-service')->assertRedirect('/account/password');
        $this->put('/account/password', ['current_password' => '10041992', 'password' => 'employee-1', 'password_confirmation' => 'employee-1']);
        $this->get('/')->assertRedirect('/self-service');
        $this->get('/self-service')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SelfService/Home')
            ->where('employee.employee_number', $k->employee_number)
            ->where('benefit_limit.medical.benefit_limit', 2250000));
        // Nothing of the admin side.
        $this->get('/core-hr/employees')->assertForbidden();
        $this->get('/compensation/claims')->assertForbidden();

        // An employee who left can no longer sign in.
        auth()->logout();
        $k->update(['status' => Employee::STATUS_TERMINATED, 'termination_date' => '2026-10-04', 'termination_reason' => 'Resign']);
        $this->post('/login', ['email' => $k->employee_number, 'password' => 'employee-1'])->assertSessionHasErrors(['email' => 'Akun ini tidak aktif. Hubungi HR.']);
    }

    public function test_accounts_for_all_employees_at_once(): void
    {
        $ids = Employee::where('status', Employee::STATUS_ACTIVE)->pluck('id');
        $this->assertGreaterThan(5, $ids->count());
        $k = Employee::with('profile')->findOrFail($ids->first());
        $k->profile->update(['birth_date' => '1990-01-31']);

        $this->actingAs($this->superAdmin())->post('/settings/employee-accounts', ['employee_ids' => $ids->all()])->assertSessionHasNoErrors();
        $this->assertSame($ids->count(), User::where('role', User::ROLE_EMPLOYEE)->count());
        // Again: the existing accounts are skipped.
        $this->post('/settings/employee-accounts', ['employee_ids' => $ids->all()])->assertSessionHasNoErrors();
        $this->assertSame($ids->count(), User::where('role', User::ROLE_EMPLOYEE)->count());
        auth()->logout();

        $this->post('/login', ['email' => $k->employee_number, 'password' => '31011990'])->assertRedirect('/');
        $this->get('/self-service')->assertRedirect('/account/password');
    }

    public function test_portal_pengajuan_wait_for_hr_and_hold_the_plafon(): void
    {
        [$k, $user] = $this->employeeAccount();
        $user->update(['must_change_password' => false]);
        $this->actingAs($user);

        // Deklarasi: waiting, already holding its amount.
        $this->post('/self-service/claim', ['date' => '2026-10-05', 'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')], 'items' => [
            ['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 2000000],
        ]])->assertSessionHasNoErrors();
        $d = BenefitClaim::firstOrFail();
        $this->assertSame(['pending', 'self_service', $k->id], [$d->status, $d->source, $d->employee_id]);
        $this->getJson('/self-service/benefit-limit')->assertJsonPath('medical.pending', 2000000)->assertJsonPath('medical.remaining', 250000);
        $this->post('/self-service/claim', ['date' => '2026-10-05', 'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')], 'items' => [
            ['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 300000],
        ]])->assertSessionHasErrors('items');

        // Cuti: waiting, not in Absensi yet.
        $this->post('/self-service/leave-travel', ['type' => 'leave', 'leave_category' => 'other', 'start_date' => '2026-10-12', 'end_date' => '2026-10-14', 'origin_city' => 'Tanjung', 'destination_city' => 'Yogyakarta', 'attachment' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')]])
            ->assertSessionHasNoErrors();
        $leave_travel = LeaveTravelRequest::firstOrFail();
        $this->assertSame('pending', $leave_travel->status);
        $this->assertSame(0, AttendanceRecord::count());

        // HR decides.
        $hr = User::where('email', 'hr@eolas.local')->firstOrFail();
        $this->actingAs($hr)->get('/compensation/claims?status=pending')->assertInertia(fn (Assert $page) => $page->where('pending', 1)->has('benefit_claims.data', 1));
        $this->post("/compensation/claims/{$d->id}/verification", ['decision' => 'reject'])->assertSessionHasErrors('notes');
        $this->post("/compensation/claims/{$d->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $d->fresh()->status);
        $this->post("/time/leave-travel/{$leave_travel->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(3, AttendanceRecord::where('leave_travel_request_id', $leave_travel->id)->count());

        // Back as the employee: approved claims are used; a rejected one frees the plafon.
        $this->actingAs($user)->post('/self-service/claim', ['date' => '2026-10-05', 'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')], 'items' => [
            ['type' => 'medical', 'beneficiary' => 'spouse', 'amount' => 250000],
        ]])->assertSessionHasNoErrors();
        $second = BenefitClaim::latest('id')->firstOrFail();
        $this->actingAs($hr)->post("/compensation/claims/{$second->id}/verification", ['decision' => 'reject', 'notes' => 'Kwitansi tidak terbaca'])->assertSessionHasNoErrors();
        $this->actingAs($user)->getJson('/self-service/benefit-limit')->assertJsonPath('medical.used', 2000000)->assertJsonPath('medical.remaining', 250000);

        // Only own, waiting pengajuan can be withdrawn.
        $this->delete("/self-service/submission/claim/{$second->id}")->assertNotFound();
        $this->post('/self-service/leave-travel', ['type' => 'business_trip', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', 'attachment' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')]]);
        $this->delete('/self-service/submission/leave-travel/'.LeaveTravelRequest::latest('id')->value('id'))->assertSessionHasNoErrors();
        $this->assertSame(1, LeaveTravelRequest::count());
    }
}
