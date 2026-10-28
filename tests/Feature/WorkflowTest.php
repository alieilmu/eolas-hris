<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\Workflow\Conditions;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Support\SessionKey;
use App\Support\Workflow\Approvable;
use App\Support\Workflow\Approvers;
use App\Support\Workflow\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** A pengajuan that remembers how its flow ended. */
class WorkflowTestRequest extends LeaveTravelRequest implements Approvable
{
    protected $table = 'leave_travel_requests';

    public static array $completed = [];

    public function workflowCompleted(WorkflowInstance $instance): void
    {
        self::$completed[$this->id] = $instance->status;
    }
}

/** The Workflow engine: steps, who approves them, vacant superiors, the HR fallback, and the decisions. */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        WorkflowTestRequest::$completed = [];
        $this->employee = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('position_id')->whereNotNull('work_location_id')->orderBy('id')->firstOrFail();
    }

    private function request(): WorkflowTestRequest
    {
        return WorkflowTestRequest::create(['employee_id' => $this->employee->id, 'type' => 'leave', 'start_date' => '2027-01-11', 'end_date' => '2027-01-13',
            'origin_city' => 'Balikpapan', 'destination_city' => 'Surabaya', 'status' => 'pending', 'source' => 'portal']);
    }

    /** A position outside the chart (so nothing above it unless given), held by $holders new employees with portal accounts. */
    private function position(string $name, ?Position $parent = null, int $holders = 1): array
    {
        $p = Position::findOrFail($this->employee->position_id)->replicate();
        $p->fill(['name' => $name, 'directorate_id' => null, 'division_id' => null, 'department_id' => null, 'section_id' => null, 'parent_position_id' => $parent?->id])->save();
        $users = [];
        for ($i = 0; $i < $holders; $i++) {
            $e = Employee::create(['employee_number' => (string) random_int(20000000, 29999999), 'name' => "{$name} {$i}", 'status' => Employee::STATUS_ACTIVE,
                'position_id' => $p->id, 'work_location_id' => $this->employee->work_location_id]);
            $users[] = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $e->id]);
        }

        return [$p, $users];
    }

    private function reportTo(Position $p): void
    {
        Position::whereKey($this->employee->position_id)->update(['parent_position_id' => $p->id]);
    }

    private function hr(array $over = []): User
    {
        return User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['travel-requests' => 'edit'], ...$over]);
    }

    /** @param  list<array{0: string, 1: ?string, 2?: ?array, 3?: string}>  $steps  approver type, value, conditions, mode */
    private function flow(array $steps, string $type = 'leave'): void
    {
        // HR changes the flow: a new version, the default one stays for what started on it.
        WorkflowDefinition::where('type', $type)->update(['is_active' => false]);
        $d = WorkflowDefinition::create(['type' => $type, 'name' => 'Alur Uji', 'version' => (int) WorkflowDefinition::where('type', $type)->max('version') + 1, 'is_active' => true]);
        foreach ($steps as $i => $step) {
            $d->steps()->create(['sequence' => $i + 1, 'name' => 'Langkah '.($i + 1), 'approver_type' => $step[0], 'approver_value' => $step[1],
                'conditions' => $step[2] ?? null, 'mode' => $step[3] ?? WorkflowStep::ANY]);
        }
    }

    public function test_without_a_flow_hr_approves_in_one_step_as_before(): void
    {
        $hr = $this->hr();
        $i = Workflow::start($this->request(), 'leave', $this->employee, $hr);

        // Made by the migration for cuti; a kind without one gets it on first use.
        $d = WorkflowDefinition::where('type', 'leave')->sole();
        $this->assertSame([1, 'menu', 'travel-requests'], [$d->version, $d->steps->sole()->approver_type, $d->steps->sole()->approver_value]);
        $this->assertFalse(WorkflowDefinition::where('type', 'loan')->exists());
        $this->assertSame('payroll-approval', Workflow::definition('loan')->steps->sole()->approver_value);
        $this->assertSame(1, $i->current_step);
        $this->assertContains($hr->id, $i->current_approvers);

        Workflow::approve($i, $hr, 'ok');
        $this->assertSame(WorkflowInstance::APPROVED, $i->fresh()->status);
        $this->assertSame(WorkflowInstance::APPROVED, WorkflowTestRequest::$completed[$i->subject_id]);
    }

    public function test_a_two_step_flow_goes_superior_then_hr(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        $hr = $this->hr();
        $this->flow([[Approvers::SUPERIOR, '1'], [Approvers::MENU, 'travel-requests']]);

        $i = Workflow::start($this->request(), 'leave', $this->employee, $hr);
        $this->assertSame([1, [$bossUser->id]], [$i->current_step, $i->current_approvers]);
        $this->assertFalse(Workflow::canDecide($i, $hr), 'HR waits for the superior');
        try {
            Workflow::approve($i, $hr);
            $this->fail('HR approved the superior\'s step');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $i = Workflow::approve($i, $bossUser, 'Setuju, sudah diatur penggantinya');
        $this->assertSame([2, WorkflowInstance::PENDING], [$i->current_step, $i->status]);
        $this->assertContains($hr->id, $i->current_approvers);
        $this->assertNotContains($bossUser->id, $i->current_approvers);
        $this->assertArrayNotHasKey($i->subject_id, WorkflowTestRequest::$completed, 'nothing changes before the last step');

        $i = Workflow::approve($i, $hr);
        $this->assertSame(WorkflowInstance::APPROVED, $i->status);
        $this->assertNotNull($i->completed_at);
        $this->assertSame(WorkflowInstance::APPROVED, WorkflowTestRequest::$completed[$i->subject_id]);
        $this->assertSame(['submitted', 'approved', 'approved'], $i->actions()->pluck('decision')->all());
        $this->assertSame([null, 1, 2], $i->actions()->pluck('step')->all());
        $this->assertSame('Setuju, sudah diatur penggantinya', $i->actions[1]->notes);
        $this->assertSame(['workflow.started', 'workflow.approved', 'workflow.approved'], AuditLog::where('action', 'like', 'workflow.%')->orderBy('id')->pluck('action')->all());
        $this->assertStringContainsString('langkah 2/2 · Disetujui', AuditLog::where('action', 'workflow.approved')->latest('id')->value('description'));

        try {
            Workflow::approve($i, $hr);
            $this->fail('a finished pengajuan was approved again');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_a_vacant_superior_passes_the_step_up_and_the_top_falls_to_hr(): void
    {
        [$top, [$topUser]] = $this->position('DIREKTUR UJI');
        [$vacant] = $this->position('MANAGER UJI', $top, holders: 0);
        $this->reportTo($vacant);
        $this->flow([[Approvers::SUPERIOR, '1']]);

        $i = Workflow::start($this->request(), 'leave', $this->employee);
        $this->assertSame([$topUser->id], $i->current_approvers, 'the vacant Manager passes it to the Director');

        // The Director leaves too: nobody above, so HR of the employee's location.
        Employee::where('position_id', $top->id)->update(['status' => Employee::STATUS_TERMINATED]);
        $hr = $this->hr();
        $elsewhere = $this->hr(['work_location_ids' => [999999]]);
        $j = Workflow::start($this->request(), 'leave', $this->employee);
        $this->assertContains($hr->id, $j->current_approvers);
        $this->assertNotContains($elsewhere->id, $j->current_approvers, 'HR of another location is not asked');
        $this->assertNotContains($topUser->id, $j->current_approvers);
    }

    public function test_two_levels_up_and_a_named_position(): void
    {
        [$director, [$directorUser]] = $this->position('DIREKTUR UJI');
        [$manager, [$managerUser]] = $this->position('MANAGER UJI', $director);
        $this->reportTo($manager);
        [$finance, [$a, $b]] = $this->position('FINANCE UJI', holders: 2);
        $this->flow([[Approvers::SUPERIOR, '2'], [Approvers::POSITION, (string) $finance->id]]);

        $i = Workflow::start($this->request(), 'leave', $this->employee);
        $this->assertSame([$directorUser->id], $i->current_approvers);
        $i = Workflow::approve($i, $directorUser);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $i->current_approvers);
        // Either holder is enough.
        $this->assertSame(WorkflowInstance::APPROVED, Workflow::approve($i, $b)->status);
        $this->assertNotContains($managerUser->id, $i->actions()->pluck('user_id')->all());
    }

    public function test_the_requester_never_approves_their_own(): void
    {
        // The employee who asks is also HR with the menu.
        $self = User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['travel-requests' => 'edit'], 'employee_id' => $this->employee->id]);
        $other = $this->hr();
        $this->flow([[Approvers::MENU, 'travel-requests']]);

        $i = Workflow::start($this->request(), 'leave', $this->employee, $self);
        $this->assertNotContains($self->id, $i->current_approvers);
        $this->assertContains($other->id, $i->current_approvers);
    }

    public function test_rejecting_ends_the_flow_and_only_the_requester_cancels(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        $this->flow([[Approvers::SUPERIOR, '1'], [Approvers::MENU, 'travel-requests']]);
        $requester = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);

        $i = Workflow::start($this->request(), 'leave', $this->employee, $requester);
        $i = Workflow::reject($i, $bossUser, 'Bentrok dengan jadwal shutdown');
        $this->assertSame([WorkflowInstance::REJECTED, null], [$i->status, $i->current_step]);
        $this->assertSame(WorkflowInstance::REJECTED, WorkflowTestRequest::$completed[$i->subject_id]);

        $j = Workflow::start($this->request(), 'leave', $this->employee, $requester);
        $this->assertFalse(Workflow::canCancel($j, $bossUser));
        try {
            Workflow::cancel($j, $bossUser);
            $this->fail('the superior cancelled it');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $j = Workflow::cancel($j, $requester, 'Tidak jadi');
        $this->assertSame(WorkflowInstance::CANCELLED, $j->status);
        $this->assertSame($j->id, Workflow::of(WorkflowTestRequest::find($j->subject_id))->id);
        $this->assertSame(1, AuditLog::where('action', 'workflow.cancelled')->count());
    }

    public function test_a_new_version_leaves_running_pengajuan_on_theirs(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        $this->flow([[Approvers::SUPERIOR, '1'], [Approvers::MENU, 'travel-requests']]);
        $running = Workflow::start($this->request(), 'leave', $this->employee);

        WorkflowDefinition::where('type', 'leave')->update(['is_active' => false]);
        $v2 = WorkflowDefinition::create(['type' => 'leave', 'name' => 'Alur Cuti', 'version' => 3, 'is_active' => true]);
        $v2->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1']);

        $this->assertSame($v2->id, Workflow::start($this->request(), 'leave', $this->employee)->workflow_definition_id);
        $running = Workflow::approve($running, $bossUser);
        $this->assertSame([2, WorkflowInstance::PENDING], [$running->current_step, $running->status], 'still two steps');
    }

    public function test_a_condition_adds_the_director_only_above_five_million(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        [$director, [$directorUser]] = $this->position('DIREKTUR UJI');
        $hr = $this->hr(['permissions' => ['benefit-claims' => 'edit']]);
        $this->flow([
            [Approvers::SUPERIOR, '1'],
            [Approvers::MENU, 'benefit-claims'],
            [Approvers::POSITION, (string) $director->id, [['field' => 'amount', 'op' => '>', 'value' => 5000000]]],
        ], 'claim');

        $small = Workflow::start($this->request(), 'claim', $this->employee, facts: ['amount' => 3000000], summary: 'Pengobatan Rp 3.000.000');
        $small = Workflow::approve(Workflow::approve($small, $bossUser), $hr);
        $this->assertSame(WorkflowInstance::APPROVED, $small->status, 'no director under Rp5 juta');
        $this->assertSame(['approved', 'approved', 'skipped'], array_column(Workflow::history($small), 'state'));

        $big = Workflow::start($this->request(), 'claim', $this->employee, facts: ['amount' => 7500000]);
        $big = Workflow::approve(Workflow::approve($big, $bossUser), $hr);
        $this->assertSame([3, [$directorUser->id]], [$big->current_step, $big->current_approvers]);
        $this->assertSame(['approved', 'approved', 'current'], array_column(Workflow::history($big), 'state'));
        $this->assertSame('Nominal lebih dari Rp 5.000.000', Workflow::history($big)[2]['condition']);
        $this->assertSame([$directorUser->name], Workflow::history($big)[2]['waiting_for']);
    }

    public function test_conditions_on_days_location_category_and_job_level(): void
    {
        $facts = ['days' => 6, 'work_location' => 3, 'category' => 'annual', 'job_level' => 7];
        $this->assertTrue(Conditions::pass(null, $facts));
        $this->assertTrue(Conditions::pass([['field' => 'days', 'op' => '>', 'value' => 5], ['field' => 'work_location', 'op' => 'in', 'value' => [3, 4]]], $facts));
        $this->assertFalse(Conditions::pass([['field' => 'days', 'op' => '>', 'value' => 5], ['field' => 'category', 'op' => 'not_in', 'value' => ['annual']]], $facts), 'all rules must hold');
        $this->assertTrue(Conditions::pass([['field' => 'job_level', 'op' => 'in', 'value' => ['7']]], $facts));
        $this->assertFalse(Conditions::pass([['field' => 'amount', 'op' => '<', 'value' => 100]], $facts), 'a fact the pengajuan does not have');

        // The employee's location and job level are facts without being given.
        $this->flow([[Approvers::MENU, 'travel-requests', [['field' => 'work_location', 'op' => 'in', 'value' => [$this->employee->work_location_id]]]]]);
        $this->hr();
        $i = Workflow::start($this->request(), 'leave', $this->employee);
        $this->assertSame([1, $this->employee->work_location_id], [$i->current_step, $i->facts['work_location']]);
    }

    public function test_an_all_step_waits_for_every_approver(): void
    {
        [$committee, [$a, $b]] = $this->position('KOMITE UJI', holders: 2);
        $this->flow([[Approvers::POSITION, (string) $committee->id, null, WorkflowStep::ALL]]);

        $i = Workflow::start($this->request(), 'leave', $this->employee);
        $i = Workflow::approve($i, $a);
        $this->assertSame([1, WorkflowInstance::PENDING], [$i->current_step, $i->status]);
        $this->assertFalse(Workflow::canDecide($i, $a), 'one vote each');
        $this->assertSame(0, Workflow::pendingFor($a)->count());
        $this->assertSame(1, Workflow::pendingFor($b)->count());
        $this->assertSame([$b->name], Workflow::history($i)[0]['waiting_for']);

        $this->assertSame(WorkflowInstance::APPROVED, Workflow::approve($i, $b)->status);
    }

    public function test_approvers_and_the_employee_are_notified(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        $hr = $this->hr();
        $requester = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);
        $this->flow([[Approvers::SUPERIOR, '1'], [Approvers::MENU, 'travel-requests']]);

        $i = Workflow::start($this->request(), 'leave', $this->employee, $requester, summary: 'Cuti Tahunan 3 hari');
        // The pengajuan's own notification (as before Workflow); a superior is sent to Persetujuan Saya.
        $ref = "leave_travel:{$i->subject_id}";
        $mine = fn (User $u) => DatabaseNotification::where('notifiable_id', $u->id)->where('ref', $ref)->first();
        $this->assertStringContainsString('11.01.2027 – 13.01.2027', $mine($bossUser)->data['body']);
        $this->assertSame('/approvals', $mine($bossUser)->data['url']);
        $this->assertNull($mine($hr), 'HR is told at its own step');

        $i = Workflow::approve($i, $bossUser);
        $this->assertNotNull($mine($bossUser)->read_at, 'closed once decided');
        $this->assertNull($mine($hr)->read_at);
        $this->assertSame('/time/travel-requests?type=leave&status=pending', $mine($hr)->data['url'], 'HR to their page');
        $mine = fn (User $u, string $ref) => DatabaseNotification::where('notifiable_id', $u->id)->where('ref', $ref)->first();

        Workflow::reject($i, $hr, 'Kuota cuti site penuh');
        $result = $mine($requester, "workflow-result:{$i->id}");
        $this->assertSame('Cuti ditolak', $result->data['title']);
        $this->assertStringContainsString('Kuota cuti site penuh', $result->data['body']);
        $this->assertNull($mine($hr, "workflow-result:{$i->id}"), 'not for the one who decided');
    }

    public function test_persetujuan_saya_for_a_superior_in_the_portal(): void
    {
        [$boss, [$bossUser]] = $this->position('KEPALA UJI');
        $this->reportTo($boss);
        $hr = $this->hr();
        $requester = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $this->employee->id]);
        $this->flow([[Approvers::SUPERIOR, '1'], [Approvers::MENU, 'travel-requests']]);
        $first = Workflow::start($this->request(), 'leave', $this->employee, $requester, summary: 'Cuti 3 hari');
        $second = Workflow::start($this->request(), 'leave', $this->employee, $requester, summary: 'Cuti 2 hari');

        $this->actingAs($bossUser)->get('/approvals')->assertOk()->assertInertia(fn ($p) => $p->component('Approvals/Index')
            ->where('auth.approvals', 2)->where('counts.pending', 2)->has('items', 2)
            ->where('items.0.id', $first->id)->where('items.0.can_decide', true)->where('items.0.summary', 'Cuti 3 hari')
            ->where('items.0.employee.employee_number', $this->employee->employee_number)->where('items.0.steps.0.state', 'current'));

        $this->post("/approvals/{$first->id}/reject", ['notes' => ''])->assertSessionHasErrors('notes');
        $this->post("/approvals/{$first->id}/approve", ['notes' => 'ok'])->assertRedirect();
        $this->assertSame(2, $first->fresh()->current_step);
        $this->post("/approvals/{$first->id}/approve")->assertForbidden();

        // HR: the bulk decision, with what is not theirs left out.
        $this->actingAs($hr)->post('/approvals/bulk', ['ids' => [$first->id, $second->id], 'decision' => 'approve'])
            ->assertSessionHas(SessionKey::FLASH_DATA, ['success' => '1 pengajuan disetujui. 1 dilewati karena sudah diproses atau bukan giliran Anda.']);
        $this->assertSame(WorkflowInstance::APPROVED, $first->fresh()->status);
        $this->post('/approvals/bulk', ['ids' => [$second->id], 'decision' => 'reject'])->assertSessionHasErrors('notes');

        // The employee follows their own and withdraws the other.
        $this->actingAs($requester)->get('/approvals?tab=mine')->assertInertia(fn ($p) => $p->has('items', 2)->where('items.0.id', $second->id)->where('items.0.can_cancel', true)->where('items.1.status', 'approved'));
        $this->post("/approvals/{$second->id}/cancel")->assertRedirect();
        $this->assertSame(WorkflowInstance::CANCELLED, $second->fresh()->status);
        $this->actingAs($bossUser)->get('/approvals?tab=decided')->assertInertia(fn ($p) => $p->has('items', 1)->where('items.0.id', $first->id));
    }
}
