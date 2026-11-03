<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\TimeClock\MobileClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Fase 1 week 7: clocking in and out from the phone — geofences, selfie, consent and retention (UU PDP). */
class MobileAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    private WorkLocation $location;

    /** The site office; the pit is 2 km north-east. */
    private const OFFICE = [-0.9513, 114.8862];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-10 07:00:00');
        $this->employee = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
        $this->location = $this->employee->workLocation;
        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function areas(string $mode): void
    {
        $this->actingAs($this->admin())->put("/time/settings/mobile-attendance/{$this->location->id}", ['mode' => $mode, 'geofences' => [
            ['name' => 'Kantor site', 'latitude' => self::OFFICE[0], 'longitude' => self::OFFICE[1], 'radius_m' => 300],
            ['name' => 'Pit', 'latitude' => self::OFFICE[0] + 0.018, 'longitude' => self::OFFICE[1] + 0.012, 'radius_m' => 1500],
        ]])->assertSessionHasNoErrors();
    }

    private function punch(string $type, float $lat, float $lng, int $accuracy = 12)
    {
        return $this->actingAs($this->user)->post('/self-service/clock', [
            'type' => $type, 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ]);
    }

    public function test_hr_sets_the_areas_and_how_long_photos_are_kept(): void
    {
        $this->actingAs($this->admin())->get('/time/settings?tab=mobile-attendance')->assertInertia(fn (Assert $p) => $p
            ->component('Time/Settings')->where('photo_retention_days', 90)->has('mobile_attendance_modes', 3));
        $this->put("/time/settings/mobile-attendance/{$this->location->id}", ['mode' => 'reject', 'geofences' => []])->assertSessionHasErrors('geofences');
        $this->put("/time/settings/mobile-attendance/{$this->location->id}", ['mode' => 'flag', 'geofences' => [['name' => 'X', 'latitude' => null, 'longitude' => 1, 'radius_m' => 100]]])
            ->assertSessionHasErrors('geofences.0.latitude');
        $this->areas('reject');
        $this->assertSame(['reject', 2], [$this->location->fresh()->mobile_attendance, $this->location->geofences()->count()]);
        $this->put('/time/settings/mobile-attendance-retention', ['photo_retention_days' => 30])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mobile_attendance_settings', ['photo_retention_days' => 30]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.geofence']);
    }

    public function test_the_employee_agrees_once_then_clocks_in_inside_the_area(): void
    {
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_clock.enabled', false));
        $this->punch('in', ...self::OFFICE)->assertSessionHasErrors('type');

        $this->areas('reject');
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_clock.enabled', true)->where('mobile_clock.consented', false)->where('mobile_clock.next', 'in')
            ->has('mobile_clock.geofences', 2)->has('mobile_consent.points', 5));
        $this->punch('in', ...self::OFFICE)->assertSessionHasErrors('type');

        $this->post('/self-service/clock/consent')->assertRedirect();
        $this->assertTrue(MobileClock::consented($this->employee->fresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.consent', 'user_id' => $this->user->id]);

        // In the pit, 100 m from its centre; the time is the server's.
        $this->punch('in', self::OFFICE[0] + 0.0189, self::OFFICE[1] + 0.012)->assertRedirect('/self-service')->assertSessionHasNoErrors();
        $log = AttendanceLog::where('source', 'mobile')->sole();
        $this->assertSame(['2026-11-10 07:00:00', MobileClock::STATUS_IN, false, 'Pit', 12], [
            $log->punched_at->format('Y-m-d H:i:s'), $log->status_code, $log->outside_geofence, $log->geofence->name, $log->accuracy_m,
        ]);
        $this->assertSame($this->employee->id, $log->employee_id);
        Storage::disk('local')->assertExists($log->photo_path);

        // A second tap right after is refused; later, the way home.
        $this->punch('out', ...self::OFFICE)->assertSessionHasErrors('type');
        Carbon::setTestNow('2026-11-10 17:05:00');
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_clock.next', 'out')->where('attendance_today.in', '07:00'));
        $this->punch('out', ...self::OFFICE)->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceLog::where('source', 'mobile')->count());
    }

    public function test_outside_the_area_is_refused_or_marked_as_the_location_is_set(): void
    {
        MobileClock::giveConsent($this->employee);
        $this->areas('reject');
        // 5 km south of the office.
        $this->punch('in', self::OFFICE[0] - 0.045, self::OFFICE[1])->assertSessionHasErrors('latitude');
        $this->assertSame(0, AttendanceLog::where('source', 'mobile')->count());

        $this->areas('flag');
        $this->punch('in', self::OFFICE[0] - 0.045, self::OFFICE[1])->assertSessionHasNoErrors();
        $log = AttendanceLog::where('source', 'mobile')->sole();
        $this->assertTrue($log->outside_geofence);
        $this->assertSame('Kantor site', $log->geofence->name);
        $this->assertEqualsWithDelta(5004, $log->distance_m, 30);

        // HR sees it among the marked ones, with the map, and the photo opening is recorded.
        $this->actingAs($this->admin())->get('/time/clock?tab=mobile&from=2026-11-10&until=2026-11-10')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_counts', ['all' => 1, 'outside' => 1])->where('mobile_logs.0.outside', true)->where('mobile_logs.0.photo', true));
        $this->get("/time/clock/photo/{$log->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.photo_viewed', 'subject_id' => $this->employee->id]);
    }

    public function test_photos_past_their_retention_are_deleted_and_the_punch_stays(): void
    {
        MobileClock::giveConsent($this->employee);
        $this->areas('flag');
        $this->punch('in', ...self::OFFICE);
        $log = AttendanceLog::where('source', 'mobile')->sole();

        Carbon::setTestNow('2026-12-01 07:00:00');
        $this->artisan('eolas:attendance:purge-photos')->assertSuccessful();
        Storage::disk('local')->assertExists($log->photo_path);

        Carbon::setTestNow('2027-02-09 07:00:00');
        $this->artisan('eolas:attendance:purge-photos')->expectsOutputToContain('1 foto dihapus')->assertSuccessful();
        Storage::disk('local')->assertMissing($log->photo_path);
        $log->refresh();
        $this->assertNull($log->photo_path);
        $this->assertNotNull($log->photo_deleted_at);
        $this->actingAs($this->admin())->get("/time/clock/photo/{$log->id}")->assertStatus(410);
    }

    public function test_distance_is_measured_on_the_earth(): void
    {
        // Jakarta (Monas) to Bandung (Gedung Sate): about 117 km.
        $this->assertEqualsWithDelta(117000, MobileClock::distance(-6.1754, 106.8272, -6.9025, 107.6188), 3000);
        $this->assertSame(0.0, MobileClock::distance(1, 1, 1, 1));
    }
}
