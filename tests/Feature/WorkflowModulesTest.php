<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\BenefitClaim;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use App\Models\JobOffer;
use App\Models\LeaveTravelRequest;
use App\Models\Loan;
use App\Models\Position;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Support\Security\TwoFactor;
use App\Support\Workflow\Approvers;
use App\Support\Workflow\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 1 week 4: deklarasi, pinjaman, update data and penawaran kerja through Workflow; an
 * approver's delegate; a late step reminded, then passed up to the superior.
 */
class WorkflowModulesTest extends TestCase
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    /** Signed in past two-factor (payroll pages need it). */
    private function signIn(User $user): static
    {
        if (! $user->hasTwoFactor() && TwoFactor::required($user)) {
            $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        }

        return $this->actingAs($user)->withSession([TwoFactor::SESSION_PASSED => true]);
    }

    /** A new position above the employee's, held by someone with a portal account. */
    private function superior(?Employee $of = null, string $number = '29999001'): User
    {
        $of ??= $this->employee;
        $p = Position::findOrFail($of->position_id)->replicate();
        $p->fill(['name' => "KEPALA {$number}", 'directorate_id' => null, 'division_id' => null, 'department_id' => null, 'section_id' => null, 'parent_position_id' => null])->save();
        Position::whereKey($of->position_id)->update(['parent_position_id' => $p->id]);
        $boss = Employee::create(['employee_number' => $number, 'name' => "Kepala {$number}", 'status' => Employee::STATUS_ACTIVE, 'position_id' => $p->id, 'work_location_id' => $of->work_location_id]);

        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $boss->id]);
    }

    /** Another employee with a portal account. */
    private function colleague(string $name): User
    {
        $k = Employee::create(['employee_number' => '29998001', 'name' => $name, 'status' => Employee::STATUS_ACTIVE, 'work_location_id' => $this->employee->work_location_id]);

        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $k->id, 'name' => $name]);
    }

    private function flow(string $type, array $steps): WorkflowDefinition
    {
        WorkflowDefinition::where('type', $type)->update(['is_active' => false]);
        $d = WorkflowDefinition::create(['type' => $type, 'name' => 'Alur uji', 'version' => (int) WorkflowDefinition::where('type', $type)->max('version') + 1, 'is_active' => true]);
        foreach ($steps as $i => $s) {
            $d->steps()->create(['sequence' => $i + 1, ...$s]);
        }

        return $d;
    }

    private function superiorThenHr(string $type, string $menu, array $first = []): void
    {
        $this->flow($type, [
            ['name' => 'Atasan', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1', ...$first],
            ['name' => 'HR', 'approver_type' => Approvers::MENU, 'approver_value' => $menu],
        ]);
    }

    private function claim(): BenefitClaim
    {
        $this->actingAs($this->user)->post('/self-service/claim', [
            'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000]],
            'attachment' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors();

        return BenefitClaim::latest('id')->firstOrFail();
    }

    public function test_a_deklarasi_goes_through_its_flow_and_counts_only_at_the_end(): void
    {
        // Default: HR verifies on their page, as before.
        $d = $this->claim();
        $flow = Workflow::of($d);
        $this->assertSame(['Verifikasi HR', 150000, 'medical'], [$flow->step()->name, $flow->facts['amount'], $flow->facts['category']]);
        $this->actingAs($this->admin())->post("/compensation/claims/{$d->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', $this->admin()->id], [$d->fresh()->status, $d->fresh()->verified_by]);

        // The superior first: HR waits, and sees why.
        $boss = $this->superior();
        $this->superiorThenHr('claim', 'benefit-claims');
        $e = $this->claim();
        $this->actingAs($this->admin())->post("/compensation/claims/{$e->id}/verification", ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => "Menunggu langkah \"Atasan\": {$boss->name}."]);
        $this->get('/compensation/claims')->assertInertia(fn ($p) => $p->where('benefit_claims.data.0.workflow.step', 'Atasan')->where('benefit_claims.data.0.workflow.can_decide', false));
        $this->actingAs($boss)->post('/approvals/'.Workflow::of($e)->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('pending', $e->fresh()->status);
        $this->actingAs($this->admin())->post("/compensation/claims/{$e->id}/verification", ['decision' => 'reject', 'notes' => 'Nota buram'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'Nota buram'], [$e->fresh()->status, $e->fresh()->verification_notes]);

        // Withdrawn by the employee: the flow ends with it.
        $f = $this->claim();
        $this->actingAs($this->user)->delete("/self-service/submission/claim/{$f->id}")->assertSessionHasNoErrors();
        $this->assertNull(BenefitClaim::find($f->id));
        $this->assertSame(WorkflowInstance::CANCELLED, WorkflowInstance::where('subject_type', $f->getMorphClass())->where('subject_id', $f->id)->value('status'));
    }

    public function test_a_loan_is_activated_by_the_last_approval_and_needs_two_factor_in_persetujuan_saya(): void
    {
        $boss = $this->superior();
        $this->superiorThenHr('loan', 'payroll-approval');
        $this->actingAs($this->user)->post('/self-service/loans', ['principal' => 1_000_000, 'tenor' => 4, 'start_period' => '2027-01', 'purpose' => 'Sekolah'])->assertSessionHasNoErrors();
        $loan = Loan::sole();
        $flow = Workflow::of($loan);
        $this->assertSame([[$boss->id], 1_000_000], [$flow->current_approvers, $flow->facts['amount']]);
        $this->get('/self-service/loans')->assertInertia(fn ($p) => $p->where('loans.0.workflow.step', 'Atasan'));

        $this->actingAs($boss)->post("/approvals/{$flow->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['pending', 0], [$loan->fresh()->status, $loan->installments()->count()]);

        // An admin without 2FA decides payroll only with it.
        $admin = $this->admin()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $admin->save();
        $this->actingAs($admin)->post("/approvals/{$flow->id}/approve")->assertSessionHasErrors('notes');
        $this->assertSame('pending', $loan->fresh()->status);

        $this->signIn($admin)->post("/compensation/loans/{$loan->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['active', 4, $admin->id], [$loan->fresh()->status, $loan->installments()->count(), $loan->fresh()->approved_by]);
    }

    public function test_a_loan_entered_by_hr_waits_for_someone_else(): void
    {
        $maker = User::create(['name' => 'HR Pembuat', 'email' => 'maker@test.local', 'password' => 'rahasia-123', 'role' => User::ROLE_HR,
            'permissions' => ['loans' => 'edit', 'payroll-approval' => 'edit'], 'is_active' => true]);
        $this->signIn($maker)->post('/compensation/loans', ['employee_id' => $this->employee->id, 'principal' => 2_000_000, 'tenor' => 2, 'start_period' => '2027-01'])->assertSessionHasNoErrors();
        $loan = Loan::sole();
        $flow = Workflow::of($loan);
        $this->assertContains($maker->id, $flow->current_approvers);
        $this->post("/compensation/loans/{$loan->id}/approve")->assertForbidden();
        $this->post("/approvals/{$flow->id}/approve")->assertSessionHasErrors('decision');
        $this->signIn($this->admin())->post("/compensation/loans/{$loan->id}/reject", ['note' => 'Tenor terlalu pendek'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'Tenor terlalu pendek'], [$loan->fresh()->status, $loan->fresh()->rejection_note]);
    }

    public function test_update_data_changes_the_profile_only_when_approved(): void
    {
        $boss = $this->superior();
        $this->superiorThenHr('data-change', 'data-change-requests');
        $this->actingAs($this->user)->post('/self-service/profile', ['phone' => '081299990000'])->assertSessionHasNoErrors();
        $p = EmployeeChangeRequest::sole();
        $this->get('/self-service/profile')->assertInertia(fn ($page) => $page->where('pending.workflow.step', 'Atasan'));

        Workflow::approve(Workflow::of($p), $boss);
        $this->assertNotSame('081299990000', $this->employee->profile()->value('phone'));
        $this->actingAs($this->admin())->post("/core-hr/change-requests/{$p->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', '081299990000'], [$p->fresh()->status, $this->employee->profile()->value('phone')]);
    }

    public function test_a_job_offer_is_approved_by_the_superior_of_the_position_offered(): void
    {
        $manager = $this->superior();
        $this->flow('job-offer', [['name' => 'Manajer perekrut', 'approver_type' => Approvers::SUPERIOR, 'approver_value' => '1']]);
        $candidate = Candidate::create(['recruitment_stage_id' => RecruitmentStage::orderByDesc('sort_order')->value('id'), 'name' => 'Rina Kandidat', 'email' => 'rina@test.local', 'phone' => '0811', 'position' => 'Operator', 'status' => 'Offering', 'applied_date' => '2026-10-01']);

        $this->actingAs($this->admin())->postJson("/recruitment/candidate/{$candidate->id}/offer", [
            'position_id' => $this->employee->position_id, 'salary' => 7_500_000, 'start_date' => '2027-01-04', 'notes' => 'Siap mulai Januari',
        ])->assertCreated()->assertJsonPath('offer.workflow.step', 'Manajer perekrut');
        $offer = JobOffer::sole();
        $flow = Workflow::of($offer);
        $this->assertSame([null, [$manager->id], 7_500_000], [$flow->employee_id, $flow->current_approvers, $flow->facts['amount']]);
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $manager->id)->where('ref', "job-offer:{$offer->id}")->count());

        // Not hired while it waits.
        $this->post("/recruitment/candidate/{$candidate->id}/employee", ['employee_number' => '10199001', 'name' => 'Rina Kandidat'])->assertSessionHasErrors('employee_number');

        $this->actingAs($manager)->get('/approvals')->assertInertia(fn ($p) => $p->where('items.0.type', 'job-offer')->where('items.0.employee', null));
        $this->post("/approvals/{$flow->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['approved', $manager->id], [$offer->fresh()->status, $offer->fresh()->decided_by]);
        $this->actingAs($this->admin())->getJson("/recruitment/candidate/{$candidate->id}")
            ->assertJsonPath('job_position.id', $this->employee->position_id)->assertJsonPath('hire_date', '2027-01-04');
    }

    public function test_a_delegate_decides_in_the_approvers_name(): void
    {
        $boss = $this->superior();
        $deputy = $this->colleague('Wakil Kepala');
        $this->superiorThenHr('claim', 'benefit-claims');
        $d = $this->claim();
        $flow = Workflow::of($d);
        $this->assertFalse(Workflow::canDecide($flow, $deputy));

        $this->actingAs($boss)->postJson('/approvals/delegations', ['delegate_id' => $deputy->id, 'mode' => 'range', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(3)->toDateString()])->assertCreated();
        $this->assertTrue(Workflow::canDecide($flow, $deputy));
        $this->actingAs($deputy)->get('/approvals')->assertInertia(fn ($p) => $p->where('counts.pending', 1)->where('acting_for.0', $boss->name));
        $this->post("/approvals/{$flow->id}/approve")->assertSessionHasNoErrors();
        $action = WorkflowAction::where('workflow_instance_id', $flow->id)->where('decision', 'approved')->sole();
        $this->assertSame([$deputy->id, $boss->id, "Wakil Kepala a.n. {$boss->name}"], [$action->user_id, $action->on_behalf_of, $action->user_name]);
        $this->assertSame('HR', $flow->fresh()->step()->name);
        $this->assertSame(0, Workflow::pendingFor($boss)->count());
    }

    public function test_an_on_leave_delegation_applies_only_while_the_approver_is_on_approved_leave(): void
    {
        $boss = $this->superior();
        $deputy = $this->colleague('Wakil Kepala');
        $this->superiorThenHr('claim', 'benefit-claims');
        $flow = Workflow::of($this->claim());
        ApprovalDelegation::create(['user_id' => $boss->id, 'delegate_id' => $deputy->id, 'mode' => ApprovalDelegation::ON_LEAVE, 'is_active' => true]);
        $this->assertFalse(Workflow::canDecide($flow, $deputy));

        LeaveTravelRequest::create(['employee_id' => $boss->employee_id, 'type' => 'leave', 'leave_category' => 'other', 'origin_city' => 'A', 'destination_city' => 'B',
            'start_date' => today()->subDay()->toDateString(), 'end_date' => today()->addDay()->toDateString(), 'status' => 'approved', 'source' => 'hr']);
        $this->assertTrue(Workflow::canDecide($flow, $deputy));
        $this->assertSame(1, Workflow::pendingFor($deputy)->count());
    }

    public function test_a_late_step_is_reminded_then_passed_up_to_the_superior(): void
    {
        CarbonImmutable::setTestNow('2026-11-02 09:00:00'); // Monday
        Carbon::setTestNow('2026-11-02 09:00:00');
        $boss = $this->superior();
        $bigBoss = $this->superior(Employee::findOrFail($boss->employee_id), '29999002');
        $this->superiorThenHr('claim', 'benefit-claims', ['due_days' => 2]);
        $flow = Workflow::of($this->claim());
        $this->assertSame([$boss->id], $flow->current_approvers);

        // Wednesday: inside the limit.
        Carbon::setTestNow('2026-11-04 08:00:00');
        $this->assertSame(['reminded' => 0, 'escalated' => 0], Workflow::escalate());
        // Wednesday 09:00: two working days — reminded, once.
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->assertSame(['reminded' => 1, 'escalated' => 0], Workflow::escalate());
        $this->assertSame(['reminded' => 0, 'escalated' => 0], Workflow::escalate());
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $boss->id)->where('ref', "workflow-reminder:{$flow->id}:1")->count());
        // Thursday 10:00: a working day later — passed up.
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->assertSame(['reminded' => 0, 'escalated' => 1], Workflow::escalate());
        $flow->refresh();
        $this->assertSame([$boss->id, $bigBoss->id], $flow->current_approvers);
        $this->assertSame('escalated', $flow->actions()->reorder()->latest('id')->value('decision'));
        $this->assertTrue(Workflow::canDecide($flow, $bigBoss));
        $this->assertSame(['reminded' => 0, 'escalated' => 0], Workflow::escalate());

        // The superior decides; the next step starts its own clock.
        Workflow::approve($flow, $bigBoss);
        $flow->refresh();
        $this->assertSame(['HR', null, null], [$flow->step()->name, $flow->reminded_at, $flow->escalated_at]);
        $this->artisan('eolas:workflow:escalate')->assertSuccessful();
    }
}
