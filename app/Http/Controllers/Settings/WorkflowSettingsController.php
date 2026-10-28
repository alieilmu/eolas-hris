<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Position;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Support\Audit\Audit;
use App\Support\Workflow\Approvers;
use App\Support\Workflow\FlowEditor;
use App\Support\Workflow\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan → Alur Persetujuan: the flow of each kind of pengajuan, built by HR from steps
 * (who approves, when the step applies, how long it may wait), tried on an employee before it
 * is saved, checked for employees it finds nobody for, and saved as a new version.
 */
class WorkflowSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $type = array_key_exists((string) $request->query('type'), Workflow::TYPES) ? $request->query('type') : 'leave';
        Workflow::definition($type); // the default flow, the first time
        $versions = WorkflowDefinition::with('steps')->where('type', $type)->orderByDesc('version')->limit(10)->get();
        $pending = WorkflowInstance::where('status', WorkflowInstance::PENDING)->whereIn('workflow_definition_id', $versions->pluck('id'))
            ->selectRaw('workflow_definition_id, count(*) as n')->groupBy('workflow_definition_id')->pluck('n', 'workflow_definition_id');
        $active = WorkflowDefinition::whereIn('type', array_keys(Workflow::TYPES))->where('is_active', true)->withCount('steps')->get()->keyBy('type');

        return Inertia::render('Settings/Workflows', [
            'type' => $type,
            'types' => collect(Workflow::TYPES)->map(fn ($t, $key) => [
                'value' => $key, 'label' => $t['label'],
                'version' => $active->get($key)?->version, 'steps' => $active->get($key)?->steps_count ?? 1,
            ])->values(),
            'versions' => $versions->map(fn (WorkflowDefinition $d) => FlowEditor::share($d) + ['pending' => (int) ($pending[$d->id] ?? 0)]),
            'fields' => FlowEditor::fields($type),
            'approver_types' => [
                ['value' => Approvers::SUPERIOR, 'label' => 'Atasan karyawan'],
                ['value' => Approvers::POSITION, 'label' => 'Jabatan tertentu'],
                ['value' => Approvers::MENU, 'label' => 'HR (akses menu)'],
            ],
            'positions' => Position::with('workLocation:id,name')->orderBy('name')->get(['id', 'name', 'work_location_id'])
                ->map(fn (Position $p) => ['value' => (string) $p->id, 'label' => $p->name.($p->workLocation ? " · {$p->workLocation->name}" : '')]),
            'menus' => FlowEditor::menus(),
            'hr_menu' => Workflow::TYPES[$type]['menu'],
            'employees' => Employee::where('status', Employee::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'employee_number'])
                ->map(fn (Employee $e) => ['value' => $e->id, 'label' => "{$e->name} · {$e->employee_number}"]),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->assertType($type);
        $steps = FlowEditor::validate($type, $request->all());
        $name = $request->validate(['name' => ['nullable', 'string', 'max:120']])['name'] ?? null;
        $definition = FlowEditor::save($type, $steps, $request->user(), $name);
        Audit::log('workflow.definition', $definition, Workflow::TYPES[$type]['label']." v{$definition->version} · ".count($steps).' langkah');
        Inertia::flash('success', 'Alur '.Workflow::TYPES[$type]['label']." versi {$definition->version} disimpan. Pengajuan baru memakainya; yang sedang berjalan tetap memakai versi sebelumnya.");

        return to_route('settings.workflows', ['type' => $type]);
    }

    /** The draft tried on one employee and an example pengajuan. */
    public function simulate(Request $request, string $type): JsonResponse
    {
        $this->assertType($type);
        $steps = FlowEditor::validate($type, $request->all());
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'facts' => ['nullable', 'array'],
            'facts.amount' => ['nullable', 'numeric', 'min:0'],
            'facts.days' => ['nullable', 'numeric', 'min:0'],
            'facts.hours' => ['nullable', 'numeric', 'min:0'],
            'facts.category' => ['nullable', 'string', 'max:40'],
        ], [], ['employee_id' => 'Karyawan']);
        $facts = array_filter(array_intersect_key($data['facts'] ?? [], array_flip(FlowEditor::FACTS[$type])), fn ($v) => $v !== null && $v !== '');
        $employee = Employee::findOrFail($data['employee_id']);

        return response()->json(['steps' => FlowEditor::simulate($type, $steps, $employee, $facts)]);
    }

    /** Employees the draft finds no approver for in the chart. */
    public function coverage(Request $request, string $type): JsonResponse
    {
        $this->assertType($type);

        return response()->json(['steps' => FlowEditor::coverage($type, FlowEditor::validate($type, $request->all()))]);
    }

    private function assertType(string $type): void
    {
        abort_unless(array_key_exists($type, Workflow::TYPES), 404);
    }
}
