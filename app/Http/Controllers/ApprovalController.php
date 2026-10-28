<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Support\Workflow\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Persetujuan Saya: every pengajuan waiting for this account's decision, whatever its kind,
 * approved or rejected one by one or many at once; what the account decided before; and the
 * account's own pengajuan with where they are in their flow. For admins and for employees
 * (superiors approve from the portal).
 */
class ApprovalController extends Controller
{
    private const TABS = ['pending', 'decided', 'mine'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'pending';
        $type = array_key_exists((string) $request->query('type'), Workflow::TYPES) ? $request->query('type') : null;

        $query = match ($tab) {
            'pending' => Workflow::pendingFor($user)->oldest('id'),
            'decided' => WorkflowInstance::whereHas('actions', fn ($q) => $q->where('user_id', $user->id)->whereIn('decision', ['approved', 'rejected']))->latest('id'),
            'mine' => WorkflowInstance::where('employee_id', $user->employee_id ?? 0)->latest('id'),
        };
        $items = $query->when($type, fn ($q) => $q->whereHas('definition', fn ($d) => $d->where('type', $type)))
            ->with('definition.steps', 'actions')->limit(100)->get();
        $employees = Employee::withoutGlobalScopes()->with('position:id,name', 'workLocation:id,name')
            ->whereIn('id', $items->pluck('employee_id')->unique())->get()->keyBy('id');

        return Inertia::render('Approvals/Index', [
            'tab' => $tab,
            'type' => $type,
            'types' => collect(Workflow::TYPES)->map(fn ($t, $k) => ['value' => $k, 'label' => $t['label']])->values(),
            'counts' => ['pending' => Workflow::pendingFor($user)->count()],
            'has_own' => $user->employee_id !== null,
            'items' => $items->map(fn (WorkflowInstance $i) => $this->item($i, $employees->get($i->employee_id), $user))->values(),
        ]);
    }

    public function approve(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:500']])['notes'] ?? null;
        Workflow::approve($instance, $request->user(), $notes);
        Inertia::flash('success', 'Pengajuan disetujui.');

        return back();
    }

    public function reject(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $notes = $request->validate(['notes' => ['required', 'string', 'max:500']], ['notes.required' => 'Tulis alasan penolakan.'])['notes'];
        Workflow::reject($instance, $request->user(), $notes);
        Inertia::flash('success', 'Pengajuan ditolak.');

        return back();
    }

    public function cancel(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        Workflow::cancel($instance, $request->user());
        Inertia::flash('success', 'Pengajuan dibatalkan.');

        return back();
    }

    /** Many at once; those already decided (or not this account's to decide) are left out and counted. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['ids.required' => 'Pilih pengajuan yang akan diproses.']);
        if ($data['decision'] === 'reject' && blank($data['notes'] ?? null)) {
            throw ValidationException::withMessages(['notes' => 'Tulis alasan penolakan.']);
        }
        $done = 0;
        $skipped = 0;
        foreach (WorkflowInstance::whereIn('id', $data['ids'])->get() as $instance) {
            try {
                $data['decision'] === 'approve'
                    ? Workflow::approve($instance, $request->user(), $data['notes'] ?? null)
                    : Workflow::reject($instance, $request->user(), $data['notes']);
                $done++;
            } catch (HttpException) {
                $skipped++;
            }
        }
        $verb = $data['decision'] === 'approve' ? 'disetujui' : 'ditolak';
        Inertia::flash('success', "{$done} pengajuan {$verb}.".($skipped ? " {$skipped} dilewati karena sudah diproses atau bukan giliran Anda." : ''));

        return back();
    }

    private function item(WorkflowInstance $i, ?Employee $k, User $user): array
    {
        $steps = Workflow::history($i);

        return [
            'id' => $i->id,
            'type' => $i->definition->type,
            'type_label' => Workflow::TYPES[$i->definition->type]['label'] ?? $i->definition->type,
            'summary' => $i->summary,
            'url' => $i->url,
            'status' => $i->status,
            'status_label' => WorkflowInstance::STATUS_LABELS[$i->status] ?? $i->status,
            'employee' => $k ? ['name' => $k->name, 'employee_number' => $k->employee_number, 'position' => $k->position?->name, 'location' => $k->workLocation?->name] : null,
            'submitted_at' => $i->created_at?->toIso8601String(),
            'step' => $i->current_step ? collect($steps)->firstWhere('sequence', $i->current_step)['name'] ?? null : null,
            'steps' => $steps,
            'events' => Workflow::events($i),
            'can_decide' => Workflow::canDecide($i, $user),
            'can_cancel' => Workflow::canCancel($i, $user),
        ];
    }
}
