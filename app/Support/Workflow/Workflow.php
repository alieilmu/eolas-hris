<?php

namespace App\Support\Workflow;

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\Audit\Audit;
use App\Support\Notifications\RequestNotifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The approval engine: a pengajuan starts a flow (the active version of its type's definition)
 * and goes through the steps whose conditions its facts meet; each step is decided by the
 * accounts Approvers works out when the step begins. In an "any" step one approval moves it on,
 * in an "all" step every approver must agree; one rejection ends it. The employee (or whoever
 * filed it) may cancel it while it waits. Every decision is kept (workflow_actions) and audited;
 * the approvers of each step and, at the end, the employee are notified.
 * A type without a definition gets the default flow: one step for HR with "ubah" on its menu
 * and the employee's location, which is how pengajuan were approved before Workflow.
 */
class Workflow
{
    /**
     * type => label, the menu of the HR who approve it by default (and when nobody else can), and
     * whether the employee may withdraw it (overtime comes from the attendance machines: no).
     */
    public const TYPES = [
        'leave' => ['label' => 'Cuti', 'menu' => 'travel-requests'],
        'business_trip' => ['label' => 'Perjalanan Dinas', 'menu' => 'travel-requests'],
        'overtime' => ['label' => 'Lembur', 'menu' => 'overtime-approval', 'cancellable' => false],
        'claim' => ['label' => 'Deklarasi Benefit', 'menu' => 'benefit-claims'],
        'loan' => ['label' => 'Pinjaman', 'menu' => 'payroll-approval'],
        'data-change' => ['label' => 'Pengajuan Update Data', 'menu' => 'data-change-requests'],
        'job-offer' => ['label' => 'Penawaran Kerja', 'menu' => 'recruitment'],
    ];

    /** Inside quietly(): pengajuan made or decided in bulk, notified and audited once by the caller. */
    private static bool $quiet = false;

    /** @var array<int, int> approver user id => pengajuan now waiting for them, while quiet */
    private static array $waiting = [];

    /**
     * Runs $work without a notification or an audit entry per pengajuan (overtime from the
     * machines: hundreds a day). Returns what $work returned and, per approver of a step other
     * than HR, how many pengajuan now wait for them, for one notification each.
     *
     * @return array{0: mixed, 1: array<int, int>}
     */
    public static function quietly(callable $work): array
    {
        [$wasQuiet, $wasWaiting] = [self::$quiet, self::$waiting];
        self::$quiet = true;
        self::$waiting = [];
        Approvers::remember(true);
        try {
            return [$work(), self::$waiting];
        } finally {
            [self::$quiet, self::$waiting] = [$wasQuiet, $wasWaiting];
            Approvers::remember(false);
        }
    }

    public static function quiet(): bool
    {
        return self::$quiet;
    }

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

    /**
     * @param  array{amount?: int|float, days?: int|float, category?: string}  $facts  what the step conditions look at
     *                                                                                   (work location and job level come from the employee)
     * @param  string|null  $summary  one line about the pengajuan, e.g. "Cuti Tahunan 3 hari, 11.01 – 13.01.2027"
     * @param  string|null  $url  its page, for the approvers
     */
    public static function start(Model $subject, string $type, Employee $employee, ?User $by = null, array $facts = [], ?string $summary = null, ?string $url = null): WorkflowInstance
    {
        $definition = self::definition($type)->load('steps');
        // Made in bulk by the system (machine overtime): nobody filed it.
        $by ??= self::$quiet ? null : auth()->user();
        $facts += ['work_location' => $employee->work_location_id, 'job_level' => $employee->job_level_id];

        return DB::transaction(function () use ($subject, $type, $employee, $by, $definition, $facts, $summary, $url) {
            $instance = WorkflowInstance::create([
                'workflow_definition_id' => $definition->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'employee_id' => $employee->id, 'status' => WorkflowInstance::PENDING, 'started_by' => $by?->id,
                'facts' => $facts, 'summary' => $summary, 'url' => $url,
            ]);
            $instance->setRelation('definition', $definition);
            self::record($instance, $by, 'submitted', null);
            self::$quiet || Audit::log('workflow.started', $subject, self::TYPES[$type]['label']." · {$employee->name} ({$employee->employee_number}) · {$definition->name} v{$definition->version}", ['instance' => $instance->id], $by);
            self::advance($instance);

            return $instance;
        });
    }

    /**
     * start() for many pengajuan of one kind at once, written in bulk (overtime from the
     * machines: thousands in a demo or a first import). Quiet: no notification or audit entry
     * per pengajuan; returns, per approver of a step other than HR, how many now wait for them.
     *
     * @param  iterable<Model>  $subjects
     * @param  callable(Model): array{0: Employee, 1: array, 2: ?string}  $describe  employee, facts, summary
     * @return array<int, int>
     */
    public static function startMany(string $type, iterable $subjects, callable $describe, ?string $url = null): array
    {
        $definition = self::definition($type)->load('steps');
        $hrMenu = self::TYPES[$type]['menu'];
        $waiting = [];
        $now = now();
        Approvers::remember(true);
        try {
            foreach (collect($subjects)->chunk(500) as $chunk) {
                $rows = [];
                $alone = [];
                foreach ($chunk as $subject) {
                    [$employee, $facts, $summary] = $describe($subject);
                    $facts += ['work_location' => $employee->work_location_id, 'job_level' => $employee->job_level_id];
                    $step = $definition->steps->first(fn (WorkflowStep $s) => Conditions::pass($s->conditions, $facts));
                    if (! $step) {
                        $alone[] = $subject; // approved at once: through start(), which tells the pengajuan

                        continue;
                    }
                    $approvers = Approvers::forStep($step, $employee, $hrMenu)->pluck('id')->all();
                    if ($step->approver_type !== Approvers::MENU) {
                        foreach ($approvers as $id) {
                            $waiting[$id] = ($waiting[$id] ?? 0) + 1;
                        }
                    }
                    $rows[] = [
                        'workflow_definition_id' => $definition->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                        'employee_id' => $employee->id, 'current_step' => $step->sequence, 'current_approvers' => json_encode($approvers),
                        'status' => WorkflowInstance::PENDING, 'facts' => json_encode($facts), 'summary' => $summary, 'url' => $url,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                $rows && DB::transaction(function () use ($rows, $now) {
                    WorkflowInstance::insert($rows);
                    $ids = WorkflowInstance::where('subject_type', $rows[0]['subject_type'] ?? '')->whereIn('subject_id', array_column($rows, 'subject_id'))
                        ->where('status', WorkflowInstance::PENDING)->where('created_at', $now)->pluck('id');
                    WorkflowAction::insert($ids->map(fn ($id) => ['workflow_instance_id' => $id, 'step' => null, 'decision' => 'submitted', 'created_at' => $now])->all());
                });
                foreach ($alone as $subject) {
                    [$employee, $facts, $summary] = $describe($subject);
                    self::quietly(fn () => self::start($subject, $type, $employee, null, $facts, $summary, $url));
                }
            }
        } finally {
            Approvers::remember(false);
        }

        return $waiting;
    }

    /** May this account decide the step the pengajuan is at (and not decided it already)? */
    public static function canDecide(WorkflowInstance $instance, User $user): bool
    {
        return $instance->isPending()
            && in_array($user->id, array_map('intval', $instance->current_approvers ?? []), true)
            && ! in_array($user->id, self::approvedAtStep($instance), true);
    }

    /** The employee who asks, or whoever filed it for them, may withdraw it while it waits. */
    public static function canCancel(WorkflowInstance $instance, User $user): bool
    {
        $instance->loadMissing('definition');

        return $instance->isPending() && (self::TYPES[$instance->definition->type]['cancellable'] ?? true)
            && ((int) $user->employee_id === (int) $instance->employee_id || ($instance->started_by && (int) $user->id === (int) $instance->started_by));
    }

    /**
     * The pengajuan is gone (deleted, or its overtime no longer worked): its flow ends as
     * cancelled, by nobody, and its notifications are closed.
     */
    public static function withdraw(Model $subject, string $reason): void
    {
        self::withdrawAll($subject::class, [$subject->getKey()], $reason, $subject);
    }

    /** withdraw() for many pengajuan of one kind at once (overtime dropped by the machines). */
    public static function withdrawAll(string $subjectClass, array $ids, string $reason, ?Model $subject = null): void
    {
        if (! $ids) {
            return;
        }
        foreach (self::pendingOf($subjectClass, $ids) as $instance) {
            $step = $instance->current_step;
            self::record($instance, null, 'cancelled', $reason);
            $instance->update(['status' => WorkflowInstance::CANCELLED, 'current_step' => null, 'current_approvers' => null, 'completed_at' => now()]);
            RequestNotifier::workflowDone($instance, $step, subject: $subject);
        }
    }

    /**
     * When a kind of pengajuan moved to Workflow (its first flow was made). Pengajuan from
     * before still finish the old way.
     */
    public static function since(string $type): ?CarbonInterface
    {
        $first = WorkflowDefinition::where('type', $type)->min('created_at');

        return $first ? CarbonImmutable::parse($first) : null;
    }

    /** For a long list (overtime): only the step and whether this account decides it. */
    public static function glance(?WorkflowInstance $instance, User $user): ?array
    {
        if (! $instance) {
            return null;
        }

        return ['id' => $instance->id, 'step' => $instance->step()?->name, 'can_decide' => self::canDecide($instance, $user)];
    }

    /** The pending flow of each pengajuan, keyed by its id: for the module's own list. */
    public static function pendingOf(string $subjectClass, iterable $ids): Collection
    {
        $ids = collect($ids)->all();

        return $ids ? WorkflowInstance::where('subject_type', (new $subjectClass)->getMorphClass())->whereIn('subject_id', $ids)
            ->where('status', WorkflowInstance::PENDING)->with('definition.steps')->get()->keyBy('subject_id') : collect();
    }

    /** Where a pending pengajuan is, for its row in a module's list: the step, who it waits for, and the whole flow. */
    public static function brief(?WorkflowInstance $instance, User $user): ?array
    {
        if (! $instance) {
            return null;
        }
        $steps = self::history($instance);
        $current = collect($steps)->firstWhere('state', 'current');

        return [
            'id' => $instance->id,
            'step' => $current['name'] ?? null,
            'waiting_for' => $current['waiting_for'] ?? [],
            'can_decide' => self::canDecide($instance, $user),
            'steps' => $steps,
            'events' => self::events($instance),
        ];
    }

    public static function approve(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canDecide($i, $by), 403, 'Anda bukan penyetuju langkah ini.');
            $step = $i->current_step;
            self::record($i, $by, 'approved', $notes);
            $mode = $i->step()?->mode ?? WorkflowStep::ANY;
            $waiting = array_diff(array_map('intval', $i->current_approvers ?? []), self::approvedAtStep($i));
            if ($mode === WorkflowStep::ALL && $waiting) {
                RequestNotifier::workflowDone($i, $step, $by);
            } else {
                RequestNotifier::workflowDone($i, $step);
                self::advance($i);
            }
            self::audit('workflow.approved', $i, $by, $notes, $step);
        });
    }

    public static function reject(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canDecide($i, $by), 403, 'Anda bukan penyetuju langkah ini.');
            $step = $i->current_step;
            self::record($i, $by, 'rejected', $notes);
            RequestNotifier::workflowDone($i, $step);
            self::finish($i, WorkflowInstance::REJECTED, $by, $notes);
            self::audit('workflow.rejected', $i, $by, $notes, $step);
        });
    }

    public static function cancel(WorkflowInstance $instance, User $by, ?string $notes = null): WorkflowInstance
    {
        return self::decide($instance, function (WorkflowInstance $i) use ($by, $notes) {
            abort_unless(self::canCancel($i, $by), 403, 'Hanya pemohon yang bisa membatalkan pengajuan ini.');
            $step = $i->current_step;
            self::record($i, $by, 'cancelled', $notes);
            RequestNotifier::workflowDone($i, $step);
            self::finish($i, WorkflowInstance::CANCELLED, $by, $notes);
            self::audit('workflow.cancelled', $i, $by, $notes, $step);
        });
    }

    /** The latest flow a pengajuan went through. */
    public static function of(Model $subject): ?WorkflowInstance
    {
        return WorkflowInstance::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->latest('id')->first();
    }

    /** Pengajuan waiting for this account's decision (Persetujuan Saya). */
    public static function pendingFor(User $user): Builder
    {
        return WorkflowInstance::where('status', WorkflowInstance::PENDING)
            ->whereJsonContains('current_approvers', $user->id)
            ->whereNotExists(fn ($q) => $q->from('workflow_actions')
                ->whereColumn('workflow_actions.workflow_instance_id', 'workflow_instances.id')
                ->whereColumn('workflow_actions.step', 'workflow_instances.current_step')
                ->where('workflow_actions.user_id', $user->id)
                ->where('workflow_actions.decision', 'approved'));
    }

    /**
     * The steps as the employee and approvers see them: decided, waiting (and for whom), still
     * to come, or left out because their conditions do not apply.
     *
     * @return list<array{sequence: int, name: string, approver: string, condition: ?string, mode: string, state: string, waiting_for: list<string>, actions: list<array>}>
     */
    public static function history(WorkflowInstance $instance): array
    {
        $instance->loadMissing('definition.steps', 'actions');
        $reached = $instance->actions->pluck('step')->filter()->unique()->all();
        $waitingFor = $instance->isPending()
            ? User::whereIn('id', array_diff(array_map('intval', $instance->current_approvers ?? []), self::approvedAtStep($instance)))->orderBy('name')->pluck('name')->all()
            : [];

        return $instance->definition->steps->map(function (WorkflowStep $s) use ($instance, $reached, $waitingFor) {
            $actions = $instance->actions->where('step', $s->sequence)->values();
            $state = match (true) {
                $instance->current_step === $s->sequence => 'current',
                $actions->contains('decision', 'rejected') => 'rejected',
                in_array($s->sequence, $reached, true) && $actions->contains('decision', 'approved') => 'approved',
                in_array($s->sequence, $reached, true) => 'stopped',
                ! Conditions::pass($s->conditions, $instance->facts ?? []) => 'skipped',
                $instance->isPending() => 'upcoming',
                default => 'skipped',
            };

            return [
                'sequence' => $s->sequence,
                'name' => $s->name,
                'approver' => self::approverLabel($s),
                'condition' => Conditions::describe($s->conditions),
                'mode' => $s->mode,
                'state' => $state,
                'waiting_for' => $state === 'current' ? $waitingFor : [],
                'actions' => $actions->map(fn (WorkflowAction $a) => self::action($a))->all(),
            ];
        })->all();
    }

    /** The submission, which belongs to no step. */
    public static function events(WorkflowInstance $instance): array
    {
        $instance->loadMissing('actions');

        return $instance->actions->where('decision', 'submitted')->map(fn (WorkflowAction $a) => self::action($a))->values()->all();
    }

    public static function approverLabel(WorkflowStep $s): string
    {
        return match ($s->approver_type) {
            Approvers::SUPERIOR => ((int) ($s->approver_value ?: 1)) <= 1 ? 'Atasan langsung' : 'Atasan '.((int) $s->approver_value).' tingkat di atas',
            Approvers::POSITION => 'Jabatan '.(Position::find((int) $s->approver_value)?->name ?? '(dihapus)'),
            Approvers::MENU => 'HR di lokasi karyawan',
            default => $s->approver_type,
        };
    }

    private static function action(WorkflowAction $a): array
    {
        return ['decision' => $a->decision, 'user' => $a->user_name, 'notes' => $a->notes, 'at' => $a->created_at?->toIso8601String()];
    }

    /** @return list<int> who approved the step the pengajuan is at now */
    private static function approvedAtStep(WorkflowInstance $instance): array
    {
        if ($instance->current_step === null) {
            return [];
        }

        return WorkflowAction::where('workflow_instance_id', $instance->id)->where('step', $instance->current_step)
            ->where('decision', 'approved')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** One decision at a time: the instance is locked and read again, so two approvers clicking at once cannot both move it. */
    private static function decide(WorkflowInstance $instance, callable $act): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $act) {
            $locked = WorkflowInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $locked->load('definition.steps');
            abort_unless($locked->isPending(), 409, 'Pengajuan ini sudah selesai diproses.');
            $act($locked);

            return $locked;
        });
    }

    /** On to the next step whose conditions hold; none left: approved. */
    private static function advance(WorkflowInstance $instance): void
    {
        $from = $instance->current_step ?? 0;
        $next = $instance->definition->steps->first(fn (WorkflowStep $s) => $s->sequence > $from && Conditions::pass($s->conditions, $instance->facts ?? []));
        if (! $next) {
            self::finish($instance, WorkflowInstance::APPROVED);

            return;
        }
        $hrMenu = self::TYPES[$instance->definition->type]['menu'];
        $employee = Employee::withoutGlobalScopes()->findOrFail($instance->employee_id);
        $instance->update(['current_step' => $next->sequence, 'current_approvers' => Approvers::forStep($next, $employee, $hrMenu)->pluck('id')->all()]);
        if (! self::$quiet) {
            RequestNotifier::workflowStep($instance);
        } elseif ($next->approver_type !== Approvers::MENU) {
            // HR were never notified of machine overtime; superiors are, once per batch.
            foreach ($instance->current_approvers as $id) {
                self::$waiting[$id] = (self::$waiting[$id] ?? 0) + 1;
            }
        }
    }

    private static function finish(WorkflowInstance $instance, string $status, ?User $by = null, ?string $notes = null): void
    {
        $instance->update(['status' => $status, 'current_step' => null, 'current_approvers' => null, 'completed_at' => now()]);
        if ($status !== WorkflowInstance::CANCELLED && ! self::$quiet) {
            RequestNotifier::workflowResult($instance, $by, $notes);
        }
        $subject = self::subject($instance);
        if ($subject instanceof Approvable) {
            $subject->workflowCompleted($instance);
        }
    }

    /** The pengajuan itself, whatever the access scope of the request deciding it. */
    public static function subject(WorkflowInstance $instance): ?Model
    {
        $class = Relation::getMorphedModel($instance->subject_type) ?? $instance->subject_type;

        return class_exists($class) ? $class::withoutGlobalScopes()->find($instance->subject_id) : null;
    }

    /** The last decision on it: who and with which note (for the module's verified_by / notes). */
    public static function lastDecision(WorkflowInstance $instance): ?WorkflowAction
    {
        return WorkflowAction::where('workflow_instance_id', $instance->id)->whereIn('decision', ['approved', 'rejected', 'cancelled'])->latest('id')->first();
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
        if (self::$quiet) {
            return;
        }
        $definition = $instance->definition;
        $total = $definition->steps->count();
        $where = $step ? "langkah {$step}/{$total}" : '';
        $end = $instance->isPending() ? '' : ' · '.WorkflowInstance::STATUS_LABELS[$instance->status];
        Audit::log($action, self::subject($instance), trim(self::TYPES[$definition->type]['label']." · {$where}{$end}".($notes ? " — {$notes}" : '')),
            ['instance' => $instance->id, 'step' => $step], $by);
    }
}
