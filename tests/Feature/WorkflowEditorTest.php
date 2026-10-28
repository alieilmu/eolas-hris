<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Support\Access\Menu;
use App\Support\Workflow\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Fase 1 week 5: Pengaturan → Alur Persetujuan — HR builds, tries and saves a flow without a programmer. */
class WorkflowEditorTest extends TestCase
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

    /** A new position above $of's, held by someone with a portal account. */
    private function superior(Employee $of, string $number): User
    {
        $p = Position::findOrFail($of->position_id)->replicate();
        $p->fill(['name' => "KEPALA {$number}", 'directorate_id' => null, 'division_id' => null, 'department_id' => null, 'section_id' => null, 'parent_position_id' => null])->save();
        Position::whereKey($of->position_id)->update(['parent_position_id' => $p->id]);
        $boss = Employee::create(['employee_number' => $number, 'name' => "Kepala {$number}", 'status' => Employee::STATUS_ACTIVE, 'position_id' => $p->id, 'work_location_id' => $of->work_location_id]);

        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $boss->id, 'name' => "Kepala {$number}"]);
    }

    /** Atasan → Manajer (only for more than 5 days) → HR. */
    private function threeSteps(): array
    {
        return ['steps' => [
            ['name' => 'Atasan langsung', 'approver_type' => 'superior', 'approver_value' => '1', 'mode' => 'any', 'due_days' => 3, 'conditions' => []],
            ['name' => 'Manajer', 'approver_type' => 'superior', 'approver_value' => '2', 'mode' => 'any', 'due_days' => null,
                'conditions' => [['field' => 'days', 'op' => '>', 'value' => 5]]],
            ['name' => 'Verifikasi HR', 'approver_type' => 'menu', 'approver_value' => 'travel-requests', 'mode' => 'any', 'due_days' => null, 'conditions' => []],
        ]];
    }

    private function leave(string $start, string $end): LeaveTravelRequest
    {
        $this->actingAs($this->user)->post('/self-service/leave-travel', [
            'type' => 'leave', 'leave_category' => 'other', 'origin_city' => 'Tanjung', 'destination_city' => 'Yogyakarta',
            'start_date' => $start, 'end_date' => $end, 'attachment' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        return LeaveTravelRequest::latest('id')->firstOrFail();
    }

    public function test_hr_builds_a_three_step_leave_flow_and_the_simulation_matches_it(): void
    {
        $boss = $this->superior($this->employee, '29999001');
        $manager = $this->superior(Employee::findOrFail($boss->employee_id), '29999002');
        $before = $this->leave('2027-01-04', '2027-01-05'); // on the default flow

        $this->actingAs($this->admin())->get('/settings/workflows?type=leave')->assertInertia(fn ($p) => $p->component('Settings/Workflows')
            ->where('versions.0.steps.0.name', 'Verifikasi HR')->where('versions.0.pending', 1)
            ->where('fields.1.key', 'category')->has('fields.1.options', 4));

        // Tried before it is saved: 3 days skip the manager, 7 days do not.
        $sim = fn (int $days) => $this->postJson('/settings/workflows/leave/simulate', $this->threeSteps() + ['employee_id' => $this->employee->id, 'facts' => ['days' => $days, 'category' => 'annual']])
            ->assertOk()->json('steps');
        $short = $sim(3);
        $this->assertSame([true, false, true], array_column($short, 'applies'));
        $this->assertSame([$boss->name], $short[0]['approvers']);
        $long = $sim(7);
        $this->assertSame([true, true, true], array_column($long, 'applies'));
        $this->assertSame([$manager->name], $long[1]['approvers']);
        $this->assertNull($long[1]['fallback']);
        $this->assertContains($this->admin()->name, $long[2]['approvers']);

        $this->post('/settings/workflows/leave', $this->threeSteps())->assertRedirect('/settings/workflows?type=leave')->assertSessionHasNoErrors();
        $v2 = WorkflowDefinition::where('type', 'leave')->where('is_active', true)->sole();
        $this->assertSame([2, 3, 3, $this->admin()->id], [$v2->version, $v2->steps()->count(), $v2->steps[0]->due_days, $v2->created_by]);
        $this->assertSame([['field' => 'days', 'op' => '>', 'value' => 5]], $v2->steps[1]->conditions);

        // The pengajuan already running keeps its version; new ones follow the new flow.
        $this->assertSame(1, Workflow::of($before)->definition->version);
        $long = Workflow::of($this->leave('2027-02-01', '2027-02-10'));
        $this->assertSame([2, [$boss->id]], [$long->definition->version, $long->current_approvers]);
        Workflow::approve($long, $boss);
        $this->assertSame(['Manajer', [$manager->id]], [$long->fresh()->step()->name, $long->fresh()->current_approvers]);
        $short = Workflow::of($this->leave('2027-03-01', '2027-03-02'));
        Workflow::approve($short, $boss);
        $this->assertSame('Verifikasi HR', $short->fresh()->step()->name, 'the manager is skipped for 2 days');
    }

    public function test_a_draft_that_does_not_fit_is_refused(): void
    {
        $this->actingAs($this->admin());
        $bad = fn (array $step) => $this->post('/settings/workflows/claim', ['steps' => [$step + ['name' => 'X', 'mode' => 'any', 'conditions' => []]]]);
        $bad(['approver_type' => 'position', 'approver_value' => '999999'])->assertSessionHasErrors('steps.0.approver_value');
        $bad(['approver_type' => 'superior', 'approver_value' => '9'])->assertSessionHasErrors('steps.0.approver_value');
        $bad(['approver_type' => 'menu', 'approver_value' => 'benefit-claims', 'conditions' => [['field' => 'amount', 'op' => 'in', 'value' => 5]]])->assertSessionHasErrors('steps.0.conditions.0.op');
        $bad(['approver_type' => 'menu', 'approver_value' => 'benefit-claims', 'conditions' => [['field' => 'hours', 'op' => '>', 'value' => 5]]])->assertSessionHasErrors('steps.0.conditions.0.field');
        $this->post('/settings/workflows/claim', ['steps' => []])->assertSessionHasErrors('steps');
        $this->assertSame(0, WorkflowDefinition::where('type', 'claim')->where('version', '>', 1)->count());

        // Only the Super Admin builds flows.
        $hr = User::create(['name' => 'HR', 'email' => 'hr-x@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR, 'permissions' => ['benefit-claims' => Menu::EDIT], 'is_active' => true]);
        $this->actingAs($hr)->get('/settings/workflows')->assertForbidden();
    }

    public function test_coverage_names_the_employees_a_superior_step_finds_nobody_for(): void
    {
        $boss = $this->superior($this->employee, '29999001');
        $loner = Employee::create(['employee_number' => '29999003', 'name' => 'Tanpa Jabatan', 'status' => Employee::STATUS_ACTIVE, 'work_location_id' => $this->employee->work_location_id]);
        $steps = ['steps' => [['name' => 'Atasan', 'approver_type' => 'superior', 'approver_value' => '1', 'mode' => 'any', 'conditions' => []],
            ['name' => 'HR', 'approver_type' => 'menu', 'approver_value' => 'loans', 'mode' => 'any', 'conditions' => []]]];

        $rows = $this->actingAs($this->admin())->postJson('/settings/workflows/loan/coverage', $steps)->assertOk()->json('steps');
        $this->assertCount(1, $rows, 'HR steps are not checked');
        $this->assertGreaterThan(0, $rows[0]['hr']);
        $this->assertContains('Karyawan belum ditempatkan di jabatan.', $rows[0]['reasons']);

        $sim = $this->postJson('/settings/workflows/loan/simulate', $steps + ['employee_id' => $loner->id, 'facts' => ['amount' => 1000000]])->json('steps');
        $this->assertSame(['hr', 'Karyawan belum ditempatkan di jabatan.'], [$sim[0]['fallback'], $sim[0]['note']]);
        $this->assertSame([$boss->name], $this->postJson('/settings/workflows/loan/simulate', $steps + ['employee_id' => $this->employee->id])->json('steps.0.approvers'));
    }
}
