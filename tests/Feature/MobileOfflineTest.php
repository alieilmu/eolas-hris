<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Position;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Support\TimeClock\AttendanceProcessor;
use App\Support\TimeClock\MobileClock;
use App\Support\Workflow\Approvers;
use App\Support\Workflow\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fase 1 week 8: punches taken without a signal and sent later (the phone's time, corrected
 * by its clock's offset; too old refused; a second send harmless), a phone clock that is off,
 * Pengajuan Lembur from the portal, and a superior approving with the attachments on the phone.
 */
class MobileOfflineTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    private const OFFICE = [-0.9513, 114.8862];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->employee = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->whereNotNull('position_id')->orderBy('id')->firstOrFail();
        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);
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

    private function useMobile(): void
    {
        $this->actingAs($this->admin())->put("/time/settings/mobile-attendance/{$this->employee->work_location_id}", ['mode' => 'flag', 'geofences' => [
            ['name' => 'Kantor site', 'latitude' => self::OFFICE[0], 'longitude' => self::OFFICE[1], 'radius_m' => 300],
        ]])->assertSessionHasNoErrors();
        MobileClock::giveConsent($this->employee);
    }

    /** As the app sends a queued punch: JSON, with the phone's times. */
    private function send(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/self-service/clock', [
            'type' => 'in', 'latitude' => self::OFFICE[0], 'longitude' => self::OFFICE[1], 'accuracy' => 15,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640), 'offline' => 1, 'user' => $this->user->id, ...$fields,
        ]);
    }

    public function test_a_punch_taken_without_a_signal_counts_at_the_phones_time_and_only_once(): void
    {
        $this->useMobile();
        $id = (string) Str::uuid();
        // Tapped at 07:02 on the phone, sent at 10:00; the phone's clock is right.
        $this->send(['id' => $id, 'taken_at' => '2026-11-10T07:02:10+07:00', 'sent_at' => '2026-11-10T10:00:00+07:00'])
            ->assertOk()->assertJsonPath('time', '07:02');
        $log = AttendanceLog::where('client_uuid', $id)->sole();
        $this->assertSame(['2026-11-10 07:02:10', true, false, '2026-11-10 10:00:00', 0], [
            $log->punched_at->format('Y-m-d H:i:s'), $log->offline, $log->time_suspect, $log->received_at->format('Y-m-d H:i:s'), $log->clock_offset_s,
        ]);

        // The app sends it again (its answer was lost): nothing new.
        $this->send(['id' => $id, 'taken_at' => '2026-11-10T07:02:10+07:00', 'sent_at' => '2026-11-10T10:00:30+07:00'])->assertOk();
        $this->assertSame(1, AttendanceLog::where('source', 'mobile')->count());

        // Queued for another account on this phone: not this one's.
        $this->send(['id' => (string) Str::uuid(), 'type' => 'out', 'taken_at' => '2026-11-10T09:00:00+07:00', 'user' => $this->user->id + 999])->assertStatus(409);

        // Beranda: the punch is today's clock-in, the next is the way home; the offline limit is told.
        $this->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('attendance_today.in', '07:02')
            ->where('mobile_clock.next', 'out')->where('mobile_clock.offline_max_hours', 24));
    }

    public function test_a_phone_clock_that_is_off_is_corrected_and_marked(): void
    {
        $this->useMobile();
        // The phone's clock is 2 hours behind: at 10:00 it says 08:00. Tapped "06:30" on it = 08:30 real.
        $this->send(['id' => (string) Str::uuid(), 'taken_at' => '2026-11-10T06:30:00+07:00', 'sent_at' => '2026-11-10T08:00:00+07:00'])->assertOk();
        $log = AttendanceLog::where('source', 'mobile')->sole();
        $this->assertSame(['2026-11-10 08:30:00', '2026-11-10 06:30:00', 7200, true], [
            $log->punched_at->format('Y-m-d H:i:s'), $log->device_time->format('Y-m-d H:i:s'), $log->clock_offset_s, $log->time_suspect,
        ]);
        $this->assertSame('Jam HP 2 jam lebih lambat', MobileClock::offsetLabel($log->clock_offset_s));

        // Sent right away from that phone: the server's time, still marked.
        Carbon::setTestNow('2026-11-10 17:00:00');
        $this->actingAs($this->user)->post('/self-service/clock', [
            'type' => 'out', 'latitude' => self::OFFICE[0], 'longitude' => self::OFFICE[1], 'accuracy' => 10, 'photo' => UploadedFile::fake()->image('s.jpg'),
            'id' => (string) Str::uuid(), 'taken_at' => '2026-11-10T15:00:00+07:00', 'sent_at' => '2026-11-10T15:00:00+07:00',
        ])->assertRedirect('/self-service')->assertSessionHasNoErrors();
        $out = AttendanceLog::where('source', 'mobile')->latest('id')->first();
        $this->assertSame(['2026-11-10 17:00:00', false, true], [$out->punched_at->format('Y-m-d H:i:s'), $out->offline, $out->time_suspect]);

        // HR: both marked, the queued one also under "offline", with the phone's time.
        $this->actingAs($this->admin())->get('/time/clock?tab=mobile&from=2026-11-10&until=2026-11-10')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_counts', ['all' => 2, 'flagged' => 2, 'offline' => 1])
            ->where('mobile_logs.1.offline', true)->where('mobile_logs.1.device_time', '2026-11-10 06:30:00')->where('mobile_logs.1.clock_offset', 'Jam HP 2 jam lebih lambat'));
    }

    public function test_a_queued_punch_older_than_the_limit_is_refused(): void
    {
        $this->useMobile();
        $this->send(['id' => (string) Str::uuid(), 'taken_at' => '2026-11-09T07:00:00+07:00', 'sent_at' => '2026-11-10T10:00:00+07:00'])
            ->assertStatus(422)->assertJsonValidationErrorFor('type');
        $this->assertSame(0, AttendanceLog::where('source', 'mobile')->count());

        // HR allows two days: it is taken.
        $this->actingAs($this->admin())->put('/time/settings/mobile-attendance-retention', ['photo_retention_days' => 90, 'offline_max_hours' => 48, 'clock_tolerance_minutes' => 10])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->get('/time/settings?tab=mobile-attendance')->assertInertia(fn (Assert $p) => $p
            ->where('mobile_attendance_limits', ['offline_max_hours' => 48, 'clock_tolerance_minutes' => 10]));
        $this->send(['id' => (string) Str::uuid(), 'taken_at' => '2026-11-09T07:00:00+07:00', 'sent_at' => '2026-11-10T10:00:00+07:00'])->assertOk();
        $this->assertSame('2026-11-09 07:00:00', AttendanceLog::where('source', 'mobile')->sole()->punched_at->format('Y-m-d H:i:s'));
    }

    public function test_the_employee_asks_for_overtime_from_the_phone_through_its_flow(): void
    {
        // Entitled to overtime (no row: entitled too).
        DB::table('overtime_eligibilities')->where('job_level_id', $this->employee->job_level_id)->update(['is_eligible' => true]);
        ShiftSchedule::where('employee_id', $this->employee->id)->whereDate('date', '2026-11-09')->delete();
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('overtime_form.eligible', true)->where('overtime_form.earliest', '2026-11-03'));

        $this->post('/self-service/overtime', ['date' => '2026-11-12', 'start' => '17:00', 'end' => '19:00', 'reason' => 'x'])->assertSessionHasErrors('date');
        $this->post('/self-service/overtime', ['date' => '2026-11-09', 'start' => '17:00', 'end' => '19:00'])->assertSessionHasErrors('reason');
        $this->post('/self-service/overtime', ['date' => '2026-11-09', 'start' => '06:00', 'end' => '20:00', 'reason' => 'Perbaikan pompa'])->assertSessionHasErrors('end');

        // A day off (no roster): every hour is overtime, × 2.
        $this->post('/self-service/overtime', ['date' => '2026-11-09', 'start' => '17:00', 'end' => '19:00', 'reason' => 'Perbaikan pompa pit 3'])->assertSessionHasNoErrors();
        $o = OvertimeRecord::where('employee_id', $this->employee->id)->whereDate('date', '2026-11-09')->sole();
        $this->assertSame(['portal', 'pending', 'rest_day', 2.0, 4.0, 'Perbaikan pompa pit 3'], [$o->source, $o->status, $o->day_type, $o->paid_hours, $o->weighted_hours, $o->notes]);
        $flow = Workflow::of($o);
        $this->assertSame([WorkflowInstance::PENDING, $this->user->id], [$flow->status, $flow->started_by]);
        $this->post('/self-service/overtime', ['date' => '2026-11-09', 'start' => '20:00', 'end' => '21:00', 'reason' => 'lagi'])->assertSessionHasErrors('date');

        // Reprocessing the day leaves it as asked; HR sees where it came from.
        AttendanceProcessor::run(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [$this->employee->id]);
        $this->assertSame(['portal', 2.0], [$o->fresh()->source, $o->fresh()->paid_hours]);
        $this->actingAs($this->admin())->get('/time/clock?tab=overtime&status=pending&from=2026-11-09&until=2026-11-09')->assertInertia(fn (Assert $p) => $p
            ->where('overtime_records.0.source', 'portal')->where('overtime_records.0.reason', 'Perbaikan pompa pit 3'));

        // Withdrawn by the employee: gone with its flow.
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('overtime_records.0.source', 'portal')->where('overtime_records.0.workflow.step', 'Verifikasi HR'));
        $this->delete("/self-service/submission/overtime/{$o->id}")->assertSessionHasNoErrors();
        $this->assertNull(OvertimeRecord::find($o->id));
        $this->assertSame(WorkflowInstance::CANCELLED, $flow->fresh()->status);
    }

    public function test_a_superior_approves_from_the_phone_seeing_the_nota(): void
    {
        $p = Position::findOrFail($this->employee->position_id)->replicate();
        $p->fill(['name' => 'KEPALA UJI', 'directorate_id' => null, 'division_id' => null, 'department_id' => null, 'section_id' => null, 'parent_position_id' => null])->save();
        Position::whereKey($this->employee->position_id)->update(['parent_position_id' => $p->id]);
        $bossEmployee = Employee::create(['employee_number' => '29999001', 'name' => 'Kepala Uji', 'status' => Employee::STATUS_ACTIVE, 'position_id' => $p->id, 'work_location_id' => $this->employee->work_location_id]);
        $boss = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $bossEmployee->id]);
        WorkflowDefinition::where('type', 'claim')->update(['is_active' => false]);
        $d = WorkflowDefinition::create(['type' => 'claim', 'name' => 'Alur uji', 'version' => 99, 'is_active' => true]);
        $d->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1']);

        $this->actingAs($this->user)->post('/self-service/claim', [
            'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000]],
            'attachment' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors();
        $claim = BenefitClaim::latest('id')->firstOrFail();
        $file = $claim->attachments()->sole();

        // The superior sees the nota in Persetujuan, without a link to the HR page.
        $this->actingAs($boss)->get('/approvals')->assertInertia(fn (Assert $p) => $p
            ->where('items.0.can_decide', true)->where('items.0.url', null)->has('items.0.attachments', 1));
        $this->get("/attachment/{$file->id}")->assertOk();

        // Someone else in the portal does not.
        $other = Employee::create(['employee_number' => '29998001', 'name' => 'Rekan', 'status' => Employee::STATUS_ACTIVE, 'work_location_id' => $this->employee->work_location_id]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $other->id]))->get("/attachment/{$file->id}")->assertNotFound();

        // Approved from the phone; the superior can still open it afterwards.
        $this->actingAs($boss)->post('/approvals/'.Workflow::of($claim)->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('approved', $claim->fresh()->status);
        $this->get("/attachment/{$file->id}")->assertOk();
    }
}
