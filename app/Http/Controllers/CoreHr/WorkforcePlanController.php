<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Recruitment\JobPostingController;
use App\Models\JobPosting;
use App\Models\Grade;
use App\Models\Position;
use App\Models\WorkforcePlanSetting;
use App\Models\WorkforcePlan;
use App\Models\WorkforcePlanItem;
use App\Models\WorkforcePlanRevision;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\WorkforcePlanning\WorkforcePlanning;
use App\Support\WorkforcePlanning\WorkforcePlanExcel;
use App\Support\Notifications\RequestNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Man Power Planning: one running plan per work location, per quarter or semester. An
 * account with "Persetujuan MPP" changes it straight away; anyone else sends a revision
 * that waits for an approver. Admins seeing every location also get the All Site overview.
 */
class WorkforcePlanController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $allSites = AccessScope::workLocationIds() === null;
        $period = WorkforcePlanning::parseCode($request->query('period')) ?? WorkforcePlanning::periodOf($today);
        $param = (string) $request->query('location', '');
        $user = $request->user();

        $base = [
            'locations' => $locations->map(fn (WorkLocation $l) => ['id' => $l->id, 'name' => $l->name]),
            'all_sites' => $allSites,
            'period' => ['code' => WorkforcePlanning::code($period), 'label' => WorkforcePlanning::label($period), 'type' => $period['type']],
            'periods' => WorkforcePlanning::periodOptions(null, $today),
            'policy' => WorkforcePlanSetting::PERIODE[WorkforcePlanSetting::current()->period],
            'can_edit' => $user->canEdit('workforce-planning'),
            'can_approve' => $user->canEdit('workforce-planning-approval'),
            'can_recruit' => $user->canEdit('recruitment'),
        ];

        if ($param === 'all' && $allSites) {
            $rows = WorkLocation::orderBy('sort_order')->get()->map(function (WorkLocation $l) use ($period) {
                $plan = WorkforcePlanning::plan($l, $period);

                return ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, ...WorkforcePlanning::summary($plan),
                    'revision' => $plan->revisions()->where('status', 'pending')->count()];
            });

            return Inertia::render('CoreHr/WorkforcePlanning', [...$base, 'mode' => 'all', 'location' => null, 'sites' => $rows]);
        }

        $location = $locations->firstWhere('id', (int) $param)
            ?? $locations->firstWhere('name', 'Head Office')
            ?? $locations->first();
        if (! $location && WorkLocation::doesntExist()) {
            // A new (empty) installation: the plan is made per work location, so those come first.
            Inertia::flash('error', 'Belum ada lokasi kerja. Buat lokasi kerja dulu di Pengaturan Core HR, lalu susun MPP-nya.');

            return redirect('/core-hr/settings');
        }
        abort_unless($location, 403);
        $location = WorkLocation::find($location->id);
        $plan = WorkforcePlanning::plan($location, $period);

        return Inertia::render('CoreHr/WorkforcePlanning', [
            ...$base,
            'mode' => 'location',
            'location' => ['id' => $location->id, 'name' => $location->name],
            'plan' => [
                'id' => $plan->id,
                'label' => $plan->label(),
                'start_date' => $plan->start_date->toDateString(),
                'end_date' => $plan->end_date->toDateString(),
                'editable' => $plan->end_date->gte($today),
                'from' => $plan->source_plan_id ? WorkforcePlan::find($plan->source_plan_id)?->label() : null,
            ],
            ...WorkforcePlanning::table($plan, $today),
            'revisions_pending' => $plan->revisions()->where('status', 'pending')->with('creator:id,name')->get()->map(fn (WorkforcePlanRevision $r) => self::revisionView($r))->values(),
            'history' => $plan->revisions()->where('status', '!=', 'pending')->with(['creator:id,name', 'approver:id,name'])->limit(20)->get()->map(fn (WorkforcePlanRevision $r) => self::revisionView($r))->values(),
            'grades' => Grade::orderBy('sort_order')->orderBy('id')->get(['id', 'code']),
            'source_options' => WorkforcePlanItem::SUMBER,
        ]);
    }

    /** Changes to the plan: saved by an approver, else sent as a revision. */
    public function save(Request $request, WorkforcePlan $plan): RedirectResponse
    {
        $this->authorizePlan($plan);
        $changes = self::diff($plan, self::validated($request, $plan));
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:255']])['notes'] ?? null;
        $msg = $this->submit($request, $plan, $changes, $notes);
        Inertia::flash('success', $msg);

        return back();
    }

    public function verify(Request $request, WorkforcePlanRevision $revision): RedirectResponse
    {
        $this->authorizePlan($revision->plan);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ], ['notes.required_if' => 'Tulis alasan penolakan.']);
        if ($revision->status !== 'pending') {
            throw ValidationException::withMessages(['revision' => 'Revisi ini sudah diputuskan.']);
        }
        $agree = $data['decision'] === 'approve';
        DB::transaction(function () use ($revision, $agree, $data, $request) {
            if ($agree) {
                self::apply($revision->plan, $revision->changes);
            }
            $revision->update(['status' => $agree ? 'approved' : 'rejected', 'verification_notes' => $data['notes'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        });
        RequestNotifier::workforcePlanResult($revision->fresh());
        Inertia::flash('success', $agree ? 'Revisi disetujui; plan diperbarui.' : 'Revisi ditolak.');

        return back();
    }

    /** Withdrawn by whoever sent it (or set aside by an approver). */
    public function cancel(Request $request, WorkforcePlanRevision $revision): RedirectResponse
    {
        $this->authorizePlan($revision->plan);
        $user = $request->user();
        abort_unless($revision->created_by === $user->id || $user->canEdit('workforce-planning-approval'), 403);
        if ($revision->status === 'pending') {
            $revision->update(['status' => 'cancelled']);
        }
        Inertia::flash('success', 'Revisi dibatalkan.');

        return back();
    }

    /** A vacancy for a position still short of people, opened in the form editor. */
    public function careers(Request $request, WorkforcePlan $plan, Position $position): RedirectResponse
    {
        $this->authorizePlan($plan);
        abort_unless($request->user()->canEdit('recruitment') && $position->work_location_id === $plan->work_location_id, 403);
        $plan->load('workLocation');
        $requirements = $request->validate(['requirements' => ['nullable', 'integer', 'min:1', 'max:999']])['requirements'] ?? null;
        $form = DB::transaction(function () use ($plan, $position, $requirements) {
            $form = JobPosting::create([
                'title' => $position->name,
                'position_title' => $position->name,
                'location' => $plan->workLocation->name,
                'position_id' => $position->id,
                'description' => "Dibutuhkan {$position->name} untuk {$plan->workLocation->name}".($requirements ? " ({$requirements} orang)" : '').'. Lengkapi kualifikasi dan benefit sebelum form dibuka.',
                'is_open' => false,
            ]);
            $form->fields()->createMany(JobPostingController::defaultFields());

            return $form;
        });
        Inertia::flash('success', "Lowongan {$position->name} dibuat (masih ditutup). Lengkapi lalu buka form-nya.");

        return redirect()->route('recruitment.form.edit', $form);
    }

    public function export(WorkforcePlan $plan): StreamedResponse
    {
        $this->authorizePlan($plan);

        return WorkforcePlanExcel::download($plan);
    }

    /** The plan from the MPP Excel: a preview first (JSON), then saved like any other change. */
    public function import(Request $request, WorkforcePlan $plan): JsonResponse|RedirectResponse
    {
        $this->authorizePlan($plan);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'], 'save' => ['nullable', 'boolean']]);
        $read = WorkforcePlanExcel::read($data['file']->getRealPath(), $plan);
        $changes = self::diff($plan, $read['items']);
        if (! $request->boolean('save')) {
            return response()->json([
                'match' => $read['match'],
                'not_matching' => $read['not_matching'],
                'changes' => self::changesView($changes),
            ]);
        }
        Inertia::flash('success', 'Import Excel: '.$this->submit($request, $plan, $changes, 'Import Excel'));

        return back();
    }

    /** Pengaturan Core HR → Man Power Planning. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', Rule::in(array_keys(WorkforcePlanSetting::PERIODE))],
            'grade_by_job_level' => ['nullable', 'array'],
            'grade_by_job_level.*' => ['nullable', 'integer', 'exists:grades,id'],
        ]);
        WorkforcePlanSetting::current()->update(['period' => $data['period'], 'grade_by_job_level' => array_filter($data['grade_by_job_level'] ?? [])]);
        WorkforcePlanSetting::forget();
        Inertia::flash('success', 'Pengaturan Man Power Planning disimpan.');

        return back();
    }

    // -------------------------------------------------------------------------------------------

    private function submit(Request $request, WorkforcePlan $plan, array $changes, ?string $notes): string
    {
        if (! $changes) {
            throw ValidationException::withMessages(['items' => 'Tidak ada perubahan.']);
        }
        if ($plan->end_date->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['items' => 'Periode ini sudah lewat; plan-nya tidak bisa diubah.']);
        }
        $user = $request->user();
        if ($user->canEdit('workforce-planning-approval')) {
            DB::transaction(function () use ($plan, $changes, $notes, $user) {
                self::apply($plan, $changes);
                WorkforcePlanRevision::create(['workforce_plan_id' => $plan->id, 'status' => 'approved', 'changes' => $changes, 'is_direct' => true, 'notes' => $notes,
                    'created_by' => $user->id, 'verified_by' => $user->id, 'verified_at' => now()]);
            });

            return count($changes).' jabatan diperbarui.';
        }
        if ($plan->revisions()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['items' => 'Masih ada revisi yang menunggu persetujuan untuk plan ini; tunggu keputusannya atau batalkan dulu.']);
        }
        $revision = WorkforcePlanRevision::create(['workforce_plan_id' => $plan->id, 'status' => 'pending', 'changes' => $changes, 'notes' => $notes, 'created_by' => $user->id]);
        RequestNotifier::workforcePlanRevision($revision);

        return 'Revisi '.count($changes).' jabatan diajukan; plan berubah setelah disetujui.';
    }

    private function authorizePlan(WorkforcePlan $plan): void
    {
        abort_unless(AccessScope::allowsWorkLocation($plan->work_location_id), 403);
    }

    /** @return array<int, array<string, mixed>> jabatan id => the fields sent */
    private static function validated(Request $request, WorkforcePlan $plan): array
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('work_location_id', $plan->work_location_id)],
            'items.*.plan' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.promotions' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.rotations' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.demotions' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.termination' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.retirements' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'items.*.sick' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'items.*.movement_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'items.*.fulfilment_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'items.*.source' => ['sometimes', 'nullable', Rule::in(array_keys(WorkforcePlanItem::SUMBER))],
            'items.*.grade_id' => ['sometimes', 'nullable', 'integer', 'exists:grades,id'],
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['items.required' => 'Tidak ada perubahan.'], ['items.*.plan' => 'Plan']);

        return collect($data['items'])->mapWithKeys(fn (array $i) => [(int) $i['position_id'] => collect($i)->only(WorkforcePlanItem::FIELDS)->all()])->all();
    }

    /**
     * {"<jabatan id>": {"<field>": [before, after]}} for what differs from the plan now.
     *
     * @param  array<int, array<string, mixed>>  $input
     */
    public static function diff(WorkforcePlan $plan, array $input): array
    {
        $items = $plan->items()->get()->keyBy('position_id');
        $out = [];
        foreach ($input as $positionId => $fields) {
            $item = $items[$positionId] ?? null;
            foreach ($fields as $field => $after) {
                $before = $item?->{$field} ?? (in_array($field, ['plan', 'promotions', 'rotations', 'demotions', 'termination'], true) ? 0 : null);
                $after = $after === '' ? null : (is_numeric($after) && $field !== 'description' ? (int) $after : $after);
                if ($after !== null && in_array($field, ['plan', 'promotions', 'rotations', 'demotions', 'termination'], true)) {
                    $after = (int) $after;
                }
                if ($before !== $after) {
                    $out[(string) $positionId][$field] = [$before, $after];
                }
            }
        }

        return $out;
    }

    /** Writes the "after" values. */
    public static function apply(WorkforcePlan $plan, array $changes): void
    {
        foreach ($changes as $positionId => $fields) {
            $values = array_map(fn (array $pair) => $pair[1], $fields);
            $item = WorkforcePlanItem::firstOrNew(['workforce_plan_id' => $plan->id, 'position_id' => (int) $positionId]);
            $item->fill($values);
            foreach (['plan', 'promotions', 'rotations', 'demotions', 'termination'] as $f) {
                $item->{$f} ??= 0;
            }
            $item->save();
        }
    }

    private static function revisionView(WorkforcePlanRevision $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'is_direct' => $r->is_direct,
            'notes' => $r->notes,
            'verification_notes' => $r->verification_notes,
            'creator' => $r->creator?->name,
            'created_by' => $r->created_by,
            'approver' => $r->approver?->name,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided' => $r->verified_at?->toIso8601String(),
            'changes' => self::changesView($r->changes),
        ];
    }

    /** The changes with the position names, for the page. */
    private static function changesView(array $changes): array
    {
        $names = Position::whereIn('id', array_keys($changes))->pluck('name', 'id');
        $grade = Grade::pluck('code', 'id');
        $show = fn (string $field, $v) => match (true) {
            $v === null => $field === 'retirements' || $field === 'sick' ? 'automatic' : '—',
            $field === 'grade_id' => $grade[$v] ?? $v,
            $field === 'source' => WorkforcePlanItem::SUMBER[$v] ?? $v,
            default => $v,
        };

        return collect($changes)->map(fn (array $fields, $id) => [
            'position_id' => (int) $id,
            'position' => $names[$id] ?? "Jabatan #{$id}",
            'fields' => collect($fields)->map(fn (array $pair, string $field) => ['field' => $field, 'before' => $show($field, $pair[0]), 'after' => $show($field, $pair[1])])->values(),
        ])->values()->all();
    }
}
