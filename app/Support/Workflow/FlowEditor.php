<?php

namespace App\Support\Workflow;

use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\OvertimeRecord;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Models\WorkLocation;
use App\Support\Access\Menu;
use App\Support\Leave\LeaveLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Pengaturan → Alur Persetujuan: what HR may build a flow from (the facts each kind of pengajuan
 * has, who can approve), a draft checked and saved as a new version (running pengajuan keep
 * theirs), a simulation of a draft for one employee, and the employees a draft finds nobody for.
 */
class FlowEditor
{
    /** type => the facts its pengajuan carry, besides work location and job level (every employee has those). */
    public const FACTS = [
        'leave' => ['days', 'category', 'amount'],
        'business_trip' => ['days', 'amount'],
        'overtime' => ['hours', 'category'],
        'claim' => ['amount', 'category'],
        'loan' => ['amount'],
        'data-change' => [],
        'job-offer' => ['amount'],
    ];

    public const NUMERIC = ['amount', 'days', 'hours'];

    /** What "category" means for each kind that has it. */
    private const CATEGORY_LABELS = ['leave' => 'Jenis cuti', 'overtime' => 'Jenis hari lembur', 'claim' => 'Jenis klaim'];

    public const MAX_STEPS = 8;

    /** @return array<string, string> the "category" values of a kind */
    public static function categories(string $type): array
    {
        return match ($type) {
            'leave' => LeaveLedger::KATEGORI,
            'overtime' => OvertimeRecord::JENIS_HARI,
            'claim' => ['medical' => 'Pengobatan', 'optical' => 'Kacamata'],
            default => [],
        };
    }

    /** What the page builds rules from: per type the fields with their kind and options. */
    public static function fields(string $type): array
    {
        $fields = [...self::FACTS[$type], 'work_location', 'job_level'];

        return collect($fields)->map(fn (string $f) => [
            'key' => $f,
            'label' => $f === 'category' ? self::CATEGORY_LABELS[$type] : Conditions::FIELDS[$f],
            'kind' => in_array($f, self::NUMERIC, true) ? 'number' : 'list',
            'options' => match ($f) {
                'category' => collect(self::categories($type))->map(fn ($l, $v) => ['value' => (string) $v, 'label' => $l])->values()->all(),
                'work_location' => WorkLocation::orderBy('name')->get(['id', 'name'])->map(fn ($w) => ['value' => (string) $w->id, 'label' => $w->name])->all(),
                'job_level' => JobLevel::orderBy('layer')->orderBy('name')->get(['id', 'name'])->map(fn ($j) => ['value' => (string) $j->id, 'label' => $j->name])->all(),
                default => [],
            },
        ])->all();
    }

    /** Menus an HR step can name: every admin menu. */
    public static function menus(): array
    {
        return collect(Menu::MODULES)->flatMap(fn (array $menus, string $module) => collect($menus)->map(fn ($label, $key) => ['value' => $key, 'label' => "{$module} · {$label}"]))->values()->all();
    }

    /**
     * The steps of a draft, checked: who approves exists, rules fit the kind's facts.
     *
     * @return list<array{name: string, approver_type: string, approver_value: ?string, mode: string, due_days: ?int, conditions: ?array}>
     */
    public static function validate(string $type, array $input): array
    {
        $menus = array_merge(...array_values(array_map('array_keys', Menu::MODULES)));
        $fields = [...self::FACTS[$type], 'work_location', 'job_level'];
        $v = Validator::make($input, [
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.approver_type' => ['required', Rule::in(array_keys(Approvers::TYPES))],
            'steps.*.approver_value' => ['nullable'],
            'steps.*.mode' => ['required', Rule::in([WorkflowStep::ANY, WorkflowStep::ALL])],
            'steps.*.due_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'steps.*.conditions' => ['nullable', 'array', 'max:5'],
            'steps.*.conditions.*.field' => ['required', Rule::in($fields)],
            'steps.*.conditions.*.op' => ['required', Rule::in(array_keys(Conditions::OPS))],
            'steps.*.conditions.*.value' => ['required'],
        ], [
            'steps.required' => 'Alur butuh minimal satu langkah.',
            'steps.max' => 'Paling banyak '.self::MAX_STEPS.' langkah.',
            'steps.*.name.required' => 'Setiap langkah perlu nama.',
            'steps.*.conditions.*.value.required' => 'Isi nilai syaratnya.',
            'steps.*.conditions.*.field.in' => 'Syarat ini tidak ada untuk jenis pengajuan ini.',
        ]);
        $v->after(function ($v) use ($input, $menus) {
            foreach ($input['steps'] ?? [] as $i => $s) {
                $n = $i + 1;
                $value = $s['approver_value'] ?? null;
                $error = match ($s['approver_type'] ?? null) {
                    Approvers::SUPERIOR => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) === false ? 'tingkat atasan 1–5.' : null,
                    Approvers::POSITION => Position::whereKey((int) $value)->exists() ? null : 'pilih jabatan penyetuju.',
                    Approvers::MENU => in_array($value, $menus, true) ? null : 'pilih menu HR.',
                    default => null,
                };
                if ($error) {
                    $v->errors()->add("steps.{$i}.approver_value", "Langkah {$n}: {$error}");
                }
                foreach ($s['conditions'] ?? [] as $j => $c) {
                    $numeric = in_array($c['field'] ?? '', self::NUMERIC, true);
                    $listOp = in_array($c['op'] ?? '', ['in', 'not_in'], true);
                    if ($numeric === $listOp) {
                        $v->errors()->add("steps.{$i}.conditions.{$j}.op", "Langkah {$n}: ".($numeric ? 'angka dibandingkan dengan lebih/kurang dari.' : 'pilihan memakai "salah satu dari" atau "bukan".'));
                    } elseif ($numeric && ! is_numeric($c['value'] ?? null)) {
                        $v->errors()->add("steps.{$i}.conditions.{$j}.value", "Langkah {$n}: nilai syarat harus angka.");
                    } elseif (! $numeric && (! is_array($c['value'] ?? null) || ! $c['value'])) {
                        $v->errors()->add("steps.{$i}.conditions.{$j}.value", "Langkah {$n}: pilih minimal satu nilai.");
                    }
                }
            }
        });
        $v->validate();

        return collect($input['steps'])->map(fn (array $s) => [
            'name' => trim($s['name']),
            'approver_type' => $s['approver_type'],
            'approver_value' => $s['approver_type'] === Approvers::SUPERIOR ? (string) (int) $s['approver_value'] : (string) $s['approver_value'],
            'mode' => $s['mode'],
            'due_days' => isset($s['due_days']) && $s['due_days'] !== '' ? (int) $s['due_days'] : null,
            'conditions' => collect($s['conditions'] ?? [])->map(fn (array $c) => [
                'field' => $c['field'], 'op' => $c['op'],
                'value' => in_array($c['field'], self::NUMERIC, true) ? (float) $c['value'] + 0 : array_values(array_map('strval', $c['value'])),
            ])->values()->all() ?: null,
        ])->values()->all();
    }

    /** A new active version of the kind's flow; the one before stays with the pengajuan already on it. */
    public static function save(string $type, array $steps, User $by, ?string $name = null): WorkflowDefinition
    {
        return DB::transaction(function () use ($type, $steps, $by, $name) {
            WorkflowDefinition::where('type', $type)->lockForUpdate()->get();
            WorkflowDefinition::where('type', $type)->update(['is_active' => false]);
            $definition = WorkflowDefinition::create([
                'type' => $type, 'name' => $name ?: 'Alur '.Workflow::TYPES[$type]['label'], 'is_active' => true, 'created_by' => $by->id,
                'version' => (int) WorkflowDefinition::where('type', $type)->max('version') + 1,
            ]);
            foreach ($steps as $i => $s) {
                $definition->steps()->create(['sequence' => $i + 1, ...$s]);
            }

            return $definition->load('steps');
        });
    }

    /** @return Collection<int, WorkflowStep> unsaved steps of a draft */
    public static function draft(array $steps): Collection
    {
        return collect($steps)->values()->map(fn (array $s, int $i) => new WorkflowStep(['sequence' => $i + 1, ...$s]));
    }

    /**
     * The draft for one employee and an example pengajuan: per step whether it applies, and who
     * would approve it (and why, when it falls back).
     */
    public static function simulate(string $type, array $steps, Employee $employee, array $facts): array
    {
        $facts += ['work_location' => $employee->work_location_id, 'job_level' => $employee->job_level_id];
        $hrMenu = Workflow::TYPES[$type]['menu'];
        $applied = 0;

        return self::draft($steps)->map(function (WorkflowStep $s) use ($facts, $employee, $hrMenu, &$applied) {
            $applies = Conditions::pass($s->conditions, $facts);
            $who = $applies ? Approvers::explain($s, $employee, $hrMenu) : null;
            $applied += $applies ? 1 : 0;

            return [
                'sequence' => $s->sequence,
                'name' => $s->name,
                'applies' => $applies,
                'condition' => Conditions::describe($s->conditions),
                'approvers' => $who ? $who['users']->pluck('name')->sort()->values()->all() : [],
                'fallback' => $who['fallback'] ?? null,
                'note' => $who['note'] ?? null,
            ];
        })->all();
    }

    /**
     * For each step with an approver from the chart, the active employees it finds nobody for
     * (the step falls to HR or the Super Admin) or only higher up than asked.
     *
     * @return list<array{sequence: int, name: string, hr: int, up: int, examples: list<string>, reasons: list<string>}>
     */
    public static function coverage(string $type, array $steps): array
    {
        $hrMenu = Workflow::TYPES[$type]['menu'];
        $employees = Employee::withoutGlobalScopes()->where('status', Employee::STATUS_ACTIVE)->get(['id', 'name', 'employee_number', 'position_id', 'work_location_id', 'sub_location_id', 'job_level_id']);
        $out = [];
        Approvers::remember(true);
        try {
            foreach (self::draft($steps) as $s) {
                if ($s->approver_type === Approvers::MENU) {
                    continue;
                }
                $row = ['sequence' => $s->sequence, 'name' => $s->name, 'hr' => 0, 'up' => 0, 'examples' => [], 'reasons' => []];
                // Same position and location: same answer (the employee only matters when they hold the approving position themselves).
                $answers = [];
                foreach ($employees as $k) {
                    $key = "{$k->position_id}:{$k->work_location_id}:{$k->sub_location_id}";
                    $answer = $answers[$key] ??= Approvers::explain($s, $k, $hrMenu);
                    if ($answer['users']->contains(fn (User $u) => (int) $u->employee_id === $k->id)) {
                        $answer = Approvers::explain($s, $k, $hrMenu);
                    }
                    if (in_array($answer['fallback'], ['hr', 'admin'], true)) {
                        $row['hr']++;
                        if (count($row['examples']) < 5) {
                            $row['examples'][] = "{$k->name} ({$k->employee_number})";
                        }
                        if ($answer['note'] && count($row['reasons']) < 5 && ! in_array($answer['note'], $row['reasons'], true)) {
                            $row['reasons'][] = $answer['note'];
                        }
                    } elseif ($answer['fallback'] === 'up') {
                        $row['up']++;
                    }
                }
                $out[] = $row;
            }
        } finally {
            Approvers::remember(false);
        }

        return $out;
    }

    /** A saved flow as the editor loads it. */
    public static function share(WorkflowDefinition $d): array
    {
        $d->loadMissing('steps');

        return [
            'id' => $d->id,
            'version' => $d->version,
            'name' => $d->name,
            'is_active' => $d->is_active,
            'created_at' => $d->created_at?->toIso8601String(),
            'created_by' => $d->created_by ? User::whereKey($d->created_by)->value('name') : null,
            'steps' => $d->steps->map(fn (WorkflowStep $s) => [
                'name' => $s->name, 'approver_type' => $s->approver_type, 'approver_value' => $s->approver_value,
                'mode' => $s->mode, 'due_days' => $s->due_days, 'conditions' => $s->conditions ?? [],
                'approver' => Workflow::approverLabel($s), 'condition' => Conditions::describe($s->conditions),
            ])->all(),
        ];
    }
}
