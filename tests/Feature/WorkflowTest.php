<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
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

    private function flow(array $steps): void
    {
        $d = WorkflowDefinition::create(['type' => 'leave', 'name' => 'Alur Cuti', 'version' => 1, 'is_active' => true]);
        foreach ($steps as $i => [$type, $value]) {
            $d->steps()->create(['sequence' => $i + 1, 'name' => "Langkah ".($i + 1), 'approver_type' => $type, 'approver_value' => $value]);
        }
    }

    public function test_without_a_flow_hr_approves_in_one_step_as_before(): void
    {
        $hr = $this->hr();
        $i = Workflow::start($this->request(), 'leave', $this->employee, $hr);

        $d = WorkflowDefinition::where('type', 'leave')->sole();
        $this->assertSame([1, 'menu', 'travel-requests'], [$d->version, $d->steps->sole()->approver_type, $d->steps->sole()->approver_value]);
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
        $v2 = WorkflowDefinition::create(['type' => 'leave', 'name' => 'Alur Cuti', 'version' => 2, 'is_active' => true]);
        $v2->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1']);

        $this->assertSame($v2->id, Workflow::start($this->request(), 'leave', $this->employee)->workflow_definition_id);
        $running = Workflow::approve($running, $bossUser);
        $this->assertSame([2, WorkflowInstance::PENDING], [$running->current_step, $running->status], 'still two steps');
    }
}
