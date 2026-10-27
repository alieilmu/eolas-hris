<?php

namespace Tests\Feature\CoreHr;

use App\Models\CertificationType;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Sertifikasi & Izin Kerja: records with scans, statuses, reminders, the portal and demo data. */
class CertificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-25 09:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-25 09:00:00');
        $this->seed();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function hr(array $menus, array $locations = []): User
    {
        return User::create(['name' => 'HR '.uniqid(), 'email' => uniqid('hr').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => $menus, 'work_location_ids' => $locations, 'is_active' => true]);
    }

    private function cert(Employee $k, string $code, ?string $expires): EmployeeCertification
    {
        return EmployeeCertification::create(['employee_id' => $k->id, 'certification_type_id' => CertificationType::where('code', $code)->value('id'), 'expires_at' => $expires]);
    }

    public function test_hr_records_a_certificate_with_its_scan_and_sees_what_runs_out(): void
    {
        $k = Employee::where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $str = CertificationType::where('code', 'STR')->firstOrFail();
        $this->actingAs($this->hr(['certifications' => 'edit']))->post('/core-hr/certifications', [
            'employee_id' => $k->id, 'certification_type_id' => $str->id, 'number' => 'STR-123', 'issuer' => 'KKI',
            'issued_at' => '2021-11-01', 'expires_at' => '2026-11-10', 'attachment' => [UploadedFile::fake()->image('str.jpg')],
        ])->assertSessionHasNoErrors();
        $c = EmployeeCertification::sole();
        $this->assertSame(1, $c->attachments()->count());
        $this->assertSame('soon', $c->status());
        $this->get('/attachment/'.$c->attachments()->first()->id)->assertOk();

        $this->cert($k, 'K3', '2026-09-01');
        $this->cert($k, 'SIO', '2027-06-01');
        $this->cert($k, 'HALAL', null);
        $this->get('/core-hr/certifications')->assertInertia(fn (Assert $p) => $p->component('CoreHr/Certifications')
            ->where('counts.expired', 1)->where('counts.soon', 1)->where('counts.valid', 1)->where('counts.lifetime', 1)
            ->where('rows.0.status', 'expired'));
        $this->get('/core-hr/certifications?status=soon')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.number', 'STR-123'));
        $this->post('/core-hr/certifications', ['employee_id' => $k->id, 'certification_type_id' => $str->id, 'issued_at' => '2026-01-01', 'expires_at' => '2025-01-01'])
            ->assertSessionHasErrors('expires_at');
        $this->get('/core-hr/certifications/export')->assertOk();

        // Only the account's locations; without the menu nothing.
        $other = \App\Models\WorkLocation::where('id', '!=', $k->work_location_id)->firstOrFail();
        $this->actingAs($this->hr(['certifications' => 'view'], [$other->id]))->get('/core-hr/certifications')
            ->assertInertia(fn (Assert $p) => $p->where('rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['employee_id'] !== $k->id)));
        $this->actingAs($this->hr(['employees' => 'view'], [$other->id]))->get('/attachment/'.$c->attachments()->first()->id)->assertNotFound();
        $this->actingAs($this->hr(['recruitment' => 'edit']))->get('/core-hr/certifications')->assertForbidden();
    }

    public function test_reminders_go_out_at_90_60_30_days_and_on_the_day_each_once(): void
    {
        [$k] = Employee::where('status', Employee::STATUS_ACTIVE)->take(1)->get()->all();
        $user = User::create(['name' => $k->name, 'email' => uniqid('k').'@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_EMPLOYEE, 'employee_id' => $k->id, 'is_active' => true]);
        $c = $this->cert($k, 'SIMPER', '2026-12-20'); // 56 days left: the 60-day step
        $far = $this->cert($k, 'KIMPER', '2027-10-01');

        $this->artisan('eolas:certifications:remind')->assertSuccessful();
        $this->assertSame(60, $c->fresh()->reminded_days);
        $this->assertNull($far->fresh()->reminded_days);
        $this->assertSame(1, $user->notifications()->count());
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->assertTrue($admin->notifications()->where('data', 'like', '%Sertifikat akan habis%')->exists());

        $this->artisan('eolas:certifications:remind');
        $this->assertSame(1, $user->notifications()->count()); // once per step

        CarbonImmutable::setTestNow('2026-11-25 09:00:00'); // 25 days left
        \Illuminate\Support\Carbon::setTestNow('2026-11-25 09:00:00');
        $this->artisan('eolas:certifications:remind');
        $this->assertSame(30, $c->fresh()->reminded_days);
        $this->assertSame(2, $user->notifications()->count());

        $this->actingAs($user)->get('/self-service/certifications')->assertInertia(fn (Assert $p) => $p->component('SelfService/Certifications')->has('rows', 2)->where('rows.0.status', 'soon'));
    }
}
