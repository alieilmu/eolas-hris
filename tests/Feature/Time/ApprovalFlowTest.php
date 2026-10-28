<?php

namespace Tests\Feature\Time;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeRecord;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Support\TimeClock\AttendanceProcessor;
use App\Support\Workflow\Approvers;
use App\Support\Workflow\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/** Cuti, dinas and lembur through Workflow: the default flow as before, a flow HR changed, and pengajuan from before Workflow. */
class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->employee = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('position_id')->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function ask(array $data = []): LeaveTravelRequest
    {
        $this->actingAs($this->user)->post('/self-service/leave-travel', [
            'type' => 'leave', 'leave_category' => 'other', 'origin_city' => 'Tanjung', 'destination_city' => 'Yogyakarta',
            'start_date' => '2027-02-01', 'end_date' => '2027-02-03', 'attachment' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')], ...$data,
        ])->assertSessionHasNoErrors();

        return LeaveTravelRequest::latest('id')->firstOrFail();
    }

    /** The employee's superior: a new position above theirs, held by someone with a portal account. */
    private function superior(): User
    {
        $p = Position::findOrFail($this->employee->position_id)->replicate();
        $p->fill(['name' => 'KEPALA UJI', 'directorate_id' => null, 'division_id' => null, 'department_id' => null, 'section_id' => null, 'parent_position_id' => null])->save();
        Position::whereKey($this->employee->position_id)->update(['parent_position_id' => $p->id]);
        $boss = Employee::create(['employee_number' => '29999001', 'name' => 'Kepala Uji', 'status' => Employee::STATUS_ACTIVE, 'position_id' => $p->id, 'work_location_id' => $this->employee->work_location_id]);

        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $boss->id]);
    }

    /** HR changes the flow of a kind: superior first, then HR. */
    private function superiorThenHr(string $type, string $menu): void
    {
        WorkflowDefinition::where('type', $type)->update(['is_active' => false]);
        $d = WorkflowDefinition::create(['type' => $type, 'name' => 'Alur baru', 'version' => 2, 'is_active' => true]);
        $d->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1']);
        $d->steps()->create(['sequence' => 2, 'name' => 'Verifikasi HR', 'approver_type' => Approvers::MENU, 'approver_value' => $menu]);
    }

    private function absensi(LeaveTravelRequest $t): int
    {
        return AttendanceRecord::where('leave_travel_request_id', $t->id)->count();
    }

    public function test_the_default_flow_is_hr_as_before(): void
    {
        $t = $this->ask();
        $flow = Workflow::of($t);
        $this->assertSame(['Verifikasi HR', 1], [$flow->step()->name, $flow->definition->version]);
        $this->assertSame(['days' => 3, 'category' => 'other'], array_intersect_key($flow->facts, ['days' => 1, 'category' => 1]));
        $this->assertSame('pending', $t->status);

        $this->actingAs($this->admin())->post("/time/leave-travel/{$t->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', WorkflowInstance::APPROVED], [$t->fresh()->status, $flow->fresh()->status]);
        $this->assertSame($this->admin()->id, $t->fresh()->verified_by);
        $this->assertSame(3, $this->absensi($t));
        $this->assertSame(0, DatabaseNotification::where('ref', "leave_travel:{$t->id}")->whereNull('read_at')->count());
    }

    public function test_a_flow_with_the_superior_fills_absensi_only_after_the_last_step(): void
    {
        $boss = $this->superior();
        $this->superiorThenHr('leave', 'travel-requests');
        $t = $this->ask();
        $this->assertSame([$boss->id], Workflow::of($t)->current_approvers);

        // HR on their page: not their turn yet.
        $this->actingAs($this->admin())->post("/time/leave-travel/{$t->id}/verification", ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => "Menunggu langkah \"Atasan\": {$boss->name}."]);
        $this->get('/time/travel-requests?type=leave')->assertInertia(fn ($p) => $p->where('leave_travel_requests.data.0.workflow.step', 'Atasan')
            ->where('leave_travel_requests.data.0.workflow.can_decide', false));

        $this->actingAs($boss)->post('/approvals/'.Workflow::of($t)->id.'/approve', ['notes' => 'Silakan'])->assertSessionHasNoErrors();
        $this->assertSame(['pending', 0], [$t->fresh()->status, $this->absensi($t)], 'nothing changes before the last step');
        $this->actingAs($this->user)->get('/self-service')->assertInertia(fn ($p) => $p->where('leave_travel_requests.0.workflow.step', 'Verifikasi HR'));

        $this->actingAs($this->admin())->post("/time/leave-travel/{$t->id}/verification", ['decision' => 'reject', 'notes' => 'Bentrok shutdown'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'Bentrok shutdown', 0], [$t->fresh()->status, $t->fresh()->verification_notes, $this->absensi($t)]);
    }

    public function test_dinas_with_a_superior_approved_through_persetujuan_saya(): void
    {
        $boss = $this->superior();
        $this->superiorThenHr('business_trip', 'travel-requests');
        $t = $this->ask(['type' => 'business_trip', 'leave_category' => null, 'destination_city' => 'Jakarta', 'needs_cash_advance' => true, 'cash_advance_amount' => 2500000]);
        $flow = Workflow::of($t);
        $this->assertSame(2500000, $flow->facts['amount']);
        $this->assertSame('/approvals', DatabaseNotification::where('notifiable_id', $boss->id)->where('ref', "leave_travel:{$t->id}")->value('data')['url'] ?? null);

        Workflow::approve($flow, $boss);
        $this->actingAs($this->admin())->post('/approvals/bulk', ['ids' => [$flow->id], 'decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $t->fresh()->status);
        $this->assertSame(3, $this->absensi($t));
    }

    public function test_the_employee_withdraws_it_in_the_portal_or_in_persetujuan_saya(): void
    {
        $t = $this->ask();
        $this->actingAs($this->user)->delete("/self-service/submission/leave-travel/{$t->id}")->assertSessionHasNoErrors();
        $this->assertNull(LeaveTravelRequest::find($t->id));
        $this->assertSame(WorkflowInstance::CANCELLED, WorkflowInstance::where('subject_id', $t->id)->value('status'));

        $u = $this->ask(['start_date' => '2027-03-01', 'end_date' => '2027-03-02']);
        $this->post('/approvals/'.Workflow::of($u)->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertNull(LeaveTravelRequest::find($u->id));

        // HR deletes one still waiting: its flow ends too.
        $v = $this->ask(['start_date' => '2027-04-01', 'end_date' => '2027-04-02']);
        $this->actingAs($this->admin())->delete("/time/leave-travel/{$v->id}")->assertSessionHasNoErrors();
        $this->assertSame(WorkflowInstance::CANCELLED, WorkflowInstance::where('subject_id', $v->id)->value('status'));
    }

    public function test_a_pengajuan_from_before_workflow_finishes_the_old_way(): void
    {
        $t = LeaveTravelRequest::create(['employee_id' => $this->employee->id, 'type' => 'leave', 'leave_category' => 'other', 'start_date' => '2027-02-01', 'end_date' => '2027-02-02',
            'origin_city' => 'Tanjung', 'destination_city' => 'Solo', 'status' => 'pending', 'source' => 'self_service']);
        $this->assertNull(Workflow::of($t));

        $this->actingAs($this->admin())->post("/time/leave-travel/{$t->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $t->fresh()->status);
        $this->assertSame(2, $this->absensi($t));
    }

    private function overtime(string $date, ?CarbonImmutable $created = null): OvertimeRecord
    {
        $o = OvertimeRecord::create(['employee_id' => $this->employee->id, 'date' => $date, 'day_type' => 'workday', 'start_date' => "{$date} 07:00:00", 'end_date' => "{$date} 19:00:00",
            'worked_minutes' => 720, 'overtime_minutes' => 180, 'paid_hours' => 3, 'weighted_hours' => 5.5, 'status' => 'pending', 'source' => 'manual']);
        if ($created) {
            $o->forceFill(['created_at' => $created])->saveQuietly();
        }

        return $o;
    }

    public function test_overtime_from_the_machines_goes_through_its_flow(): void
    {
        $boss = $this->superior();
        $this->superiorThenHr('overtime', 'overtime-approval');
        $day = CarbonImmutable::yesterday();
        OvertimeRecord::where('employee_id', $this->employee->id)->delete();
        $new = $this->overtime($day->toDateString());
        $old = $this->overtime($day->subDay()->toDateString(), CarbonImmutable::parse('2026-01-01'));

        AttendanceProcessor::run($day->subDays(2), $day, [$this->employee->id]);
        $flow = Workflow::of($new);
        $this->assertSame([$boss->id], $flow->current_approvers);
        $this->assertEquals(3, $flow->facts['hours']);
        $this->assertNull(Workflow::of($old), 'overtime from before Workflow');
        // One notification for the superior, for the lot.
        $this->assertSame('1 lembur menunggu persetujuan Anda', DatabaseNotification::where('notifiable_id', $boss->id)->value('data')['title'] ?? null);
        // Processed again: not a second flow.
        AttendanceProcessor::run($day->subDays(2), $day, [$this->employee->id]);
        $this->assertSame(1, WorkflowInstance::where('subject_id', $new->id)->where('subject_type', $new->getMorphClass())->count());

        // HR approves both on the Kehadiran page: the old one straight away, the new one waits for the superior.
        $this->actingAs($this->admin())->get('/time/clock?tab=overtime&status=pending&from='.$day->subDays(2)->toDateString().'&until='.$day->toDateString())
            ->assertInertia(fn ($p) => $p->where('overtime_records.0.workflow.step', 'Atasan')->where('overtime_records.0.workflow.can_decide', false)
                ->where('overtime_records.1.workflow', null));
        $this->post('/time/clock/overtime/verification', ['ids' => [$new->id, $old->id], 'decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(['pending', 'approved'], [$new->fresh()->status, $old->fresh()->status]);

        Workflow::approve($flow, $boss);
        $this->post('/time/clock/overtime/verification', ['ids' => [$new->id], 'decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', $this->admin()->id], [$new->fresh()->status, $new->fresh()->verified_by]);

        // Back to waiting: a fresh flow from the first step.
        $this->post('/time/clock/overtime/verification', ['ids' => [$new->id], 'decision' => 'pending'])->assertSessionHasNoErrors();
        $this->assertSame([$boss->id], Workflow::of($new)->current_approvers);
        $this->assertFalse(Workflow::canCancel(Workflow::of($new), $this->user), 'machine overtime is not withdrawn by the employee');
    }

    public function test_overtime_no_longer_worked_withdraws_its_flow(): void
    {
        $day = CarbonImmutable::yesterday();
        OvertimeRecord::where('employee_id', $this->employee->id)->delete();
        $o = $this->overtime($day->toDateString());
        $o->update(['source' => 'device']);
        Workflow::startMany('overtime', [$o], fn ($r) => [$this->employee, $r->workflowFacts(), $r->summary()]);
        $flow = Workflow::of($o);
        $this->assertTrue($flow->isPending());

        // The machines show no overtime that day any more.
        AttendanceProcessor::run($day, $day, [$this->employee->id]);
        $this->assertNull(OvertimeRecord::find($o->id));
        $this->assertSame(WorkflowInstance::CANCELLED, $flow->fresh()->status);
    }
}
