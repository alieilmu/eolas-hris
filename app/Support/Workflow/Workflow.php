<?php

namespace App\Support\Workflow;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The approval engine: a pengajuan starts a flow (the active version of its type's definition)
 * and goes step by step; each step is decided by the accounts Approvers works out when the step
 * begins. Any of them approving moves it on, one rejecting ends it; the employee (or whoever
 * filed it) may cancel it while it waits. Every decision is kept (workflow_actions) and audited.
 * A type without a definition gets the default flow: one step for HR with "ubah" on its menu
 * and the employee's location, which is how pengajuan were approved before Workflow.
 */
class Workflow
{
    /** type => label and the menu of the HR who approve it by default (and when nobody else can). */
    public const TYPES = [
        'leave' => ['label' => 'Cuti', 'menu' => 'travel-requests'],
        'business_trip' => ['label' => 'Perjalanan Dinas', 'menu' => 'travel-requests'],
        'overtime' => ['label' => 'Lembur', 'menu' => 'overtime-approval'],
        'claim' => ['label' => 'Deklarasi Benefit', 'menu' => 'benefit-claims'],
        'loan' => ['label' => 'Pinjaman', 'menu' => 'payroll-approval'],
        'data-change' => ['label' => 'Pengajuan Update Data', 'menu' => 'data-change-requests'],
        'job-offer' => ['label' => 'Penawaran Kerja', 'menu' => 'recruitment'],
    ];

    /** The flow new pengajuan of a type follow: its active version, else the default one. */
    public static function definition(string $type): WorkflowDefinition
    {
        $config = self::TYPES[$type] ?? throw new InvalidArgumentException("Jenis pengajuan tidak dikenal: {$type}");
        $active = WorkflowDefinition::where('type', $type)->where('is_active', true)->orderByDesc('version')->first();
        if ($active) {
            return $active;
        }

        return DB::transaction(function () use ($type, $config) {
            $definition = WorkflowDefinition::create([
                'type' => $type, 'name' => "Alur {$config['label']}", 'is_active' => true,
                'version' => (int) WorkflowDefinition::where('type', $type)->max('version') + 1,
            ]);
            $definition->steps()->create(['sequence' => 1, 'name' => 'Verifikasi HR', 'approver_type' => Approvers::MENU, 'approver_value' => $config['menu']]);

            return $definition;
        });
    }

    public static function start(Model $subject, string $type, Employee $employee, ?User $by = null): WorkflowInstance
    {
        $definition = self::definition($type)->load('steps');
        $by ??= auth()->user();

        return DB::transaction(function () use ($subject, $type, $employee, $by, $definition) {
            $instance = WorkflowInstance::create([
                'workflow_definition_id' => $definition->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'employee_id' => $employee->id, 'status' => WorkflowInstance::PENDING, 'started_by' => $by?->id,
            ]);
            $instance->setRelation('definition', $definition);
            self::record($instance, $by, 'submitted', null);
            Audit::log('workflow.started', $subject, self::TYPES[$type]['label']." · {$employee->name} ({$employee->employee_number}) · {$definition->name} v{$definition->version}", ['instance' => $instance->id], $by);

            $first = $definition->steps->first();
            $first ? self::enter($instance, $first) : self::finish($instance, WorkflowInstance::APPROVED);

            return $instance;
        });
    }

    /** May this account decide the step the pengajuan is at? */
    public static function canDecide(WorkflowInstance $instance, User $user): bool
    {
        return $instance->isPending() && in_array($user->id, array_map('intval', $instance->current_approvers ?? []), true);
    }

    /** The employee who asks, or whoever filed it for them, may withdraw it while it waits. */
    public static function canCancel(WorkflowInstance $instance, User $user): bool
    {
        return $instance->isPending() && ((int) $user->employee_id === (int) $instance->employee_id || (int) $user->id === (int) $instance->started_by);
    }

    public static function approve(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, $by, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canDecide($i, $by), 403, 'Anda bukan penyetuju langkah ini.');
            $step = $i->current_step;
            self::record($i, $by, 'approved', $notes);
            $next = $i->definition->steps->first(fn (WorkflowStep $s) => $s->sequence > $step);
            $next ? self::enter($i, $next) : self::finish($i, WorkflowInstance::APPROVED);
            self::audit('workflow.approved', $i, $by, $notes, $step);
        });
    }

    public static function reject(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, $by, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canDecide($i, $by), 403, 'Anda bukan penyetuju langkah ini.');
            $step = $i->current_step;
            self::record($i, $by, 'rejected', $notes);
            self::finish($i, WorkflowInstance::REJECTED);
            self::audit('workflow.rejected', $i, $by, $notes, $step);
        });
    }

    public static function cancel(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, $by, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canCancel($i, $by), 403, 'Hanya pemohon yang bisa membatalkan pengajuan ini.');
            $step = $i->current_step;
            self::record($i, $by, 'cancelled', $notes);
            self::finish($i, WorkflowInstance::CANCELLED);
            self::audit('workflow.cancelled', $i, $by, $notes, $step);
        });
    }

    /** The latest flow a pengajuan went through. */
    public static function of(Model $subject): ?WorkflowInstance
    {
        return WorkflowInstance::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->latest('id')->first();
    }

    /** One decision at a time: the instance is locked and read again, so two approvers clicking at once cannot both move it. */
    private static function decide(WorkflowInstance $instance, User $by, callable $act): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $act) {
            $locked = WorkflowInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $locked->load('definition.steps');
            abort_unless($locked->isPending(), 409, 'Pengajuan ini sudah selesai diproses.');
            $act($locked);

            return $locked;
        });
    }

    private static function enter(WorkflowInstance $instance, WorkflowStep $step): void
    {
        $hrMenu = self::TYPES[$instance->definition->type]['menu'];
        $employee = Employee::withoutGlobalScopes()->findOrFail($instance->employee_id);
        $instance->update(['current_step' => $step->sequence, 'current_approvers' => Approvers::forStep($step, $employee, $hrMenu)->pluck('id')->all()]);
    }

    private static function finish(WorkflowInstance $instance, string $status): void
    {
        $instance->update(['status' => $status, 'current_step' => null, 'current_approvers' => null, 'completed_at' => now()]);
        $subject = $instance->subject;
        if ($subject instanceof Approvable) {
            $subject->workflowCompleted($instance);
        }
    }

    private static function record(WorkflowInstance $instance, ?User $by, string $decision, ?string $notes): void
    {
        WorkflowAction::create([
            'workflow_instance_id' => $instance->id, 'step' => $instance->current_step, 'user_id' => $by?->id, 'user_name' => $by?->name,
            'decision' => $decision, 'notes' => $notes ?: null, 'created_at' => now(),
        ]);
    }

    private static function audit(string $action, WorkflowInstance $instance, User $by, ?string $notes, ?int $step): void
    {
        $definition = $instance->definition;
        $total = $definition->steps->count();
        $where = $step ? "langkah {$step}/{$total}" : '';
        $end = $instance->isPending() ? '' : ' · '.WorkflowInstance::STATUS_LABELS[$instance->status];
        Audit::log($action, $instance->subject, trim(self::TYPES[$definition->type]['label']." · {$where}{$end}".($notes ? " — {$notes}" : '')),
            ['instance' => $instance->id, 'step' => $step], $by);
    }
}
