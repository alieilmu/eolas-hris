<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Organization\PositionComposer;
use App\Support\Organization\OrgChartChanges;
use App\Support\Organization\SitePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Mode Organisasi of Struktur Organisasi: which positions each work location
 * has and who reports to whom. A site starts from its Project Manager (a
 * person or vacant, optionally with a Deputy); units and Layer 1–2 titles are
 * then dropped in from the list on the right. Changing it is for Admin / HR.
 */
class OrgPlanController extends Controller
{
    /** The old address of the plan: now a mode of Struktur Organisasi. */
    public function show(Request $request): RedirectResponse
    {
        return redirect()->route('core_hr.org-chart', array_filter(['location' => $request->query('location'), 'mode' => 'organization']));
    }

    /** @return array<string, mixed>|null the Project Manager choice, while a site is not set up */
    public static function setupProps(): ?array
    {
        return [
            'pm_job_level' => SitePlan::pmSubGroup()?->name,
            'dpm_job_level' => SitePlan::dpmSubGroup()?->name,
            'pm_candidates' => SitePlan::candidates(SitePlan::pmSubGroup()),
            'dpm_candidates' => SitePlan::candidates(SitePlan::dpmSubGroup()),
            // The HO positions a Project Manager can report to (not of a lower layer).
            'superior_options' => Position::with('jobLevel')
                ->whereHas('workLocation', fn ($q) => $q->where('type', WorkLocation::TYPE_HEAD_OFFICE))
                ->whereHas('jobLevel', fn ($q) => $q->where('layer', '>=', SitePlan::pmSubGroup()?->layer ?? 0))
                ->orderBy('name')->get()->map(fn (Position $j) => ['id' => $j->id, 'name' => $j->name])->values(),
        ];
    }

    /** A site's first step: its Project Manager (someone, or vacant) and, if needed, a Deputy. */
    public function setup(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        abort_unless(AccessScope::allowsWorkLocation($workLocation->id), 403);
        $data = $request->validate([
            'pm_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'deputy' => ['boolean'],
            'dpm_employee_id' => ['nullable', 'integer', 'exists:employees,id', 'different:pm_employee_id'],
            'superior_position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ], ['dpm_employee_id.different' => 'Project Manager dan Deputy-nya harus orang yang berbeda.']);

        if ($workLocation->isHeadOffice()) {
            throw ValidationException::withMessages(['setup' => 'Head Office tidak memakai Project Manager.']);
        }
        if (SitePlan::pmOf($workLocation)) {
            throw ValidationException::withMessages(['setup' => "{$workLocation->name} sudah punya jabatan Project Manager."]);
        }
        $pmSub = SitePlan::pmSubGroup() ?? throw ValidationException::withMessages(['setup' => 'Belum ada SubGroup yang dinamai dari Work Location (mis. Project Manager) di Master Data.']);
        $dpmSub = SitePlan::dpmSubGroup();
        if (($data['deputy'] ?? false) && ! $dpmSub) {
            throw ValidationException::withMessages(['setup' => 'Belum ada SubGroup Deputy Project Manager di Master Data.']);
        }

        $linked = DB::transaction(function () use ($data, $workLocation, $pmSub, $dpmSub) {
            [$pm] = PositionComposer::findOrCreate(['work_location_id' => $workLocation->id, 'job_level_id' => $pmSub->id]);
            $ops = [];
            if ($data['superior_position_id'] ?? null) {
                $ops[] = ['type' => 'reparent', 'position' => $pm->id, 'parent' => $data['superior_position_id']];
            }
            (new OrgChartChanges)->apply($ops);
            self::fill($pm, $data['pm_employee_id'] ?? null);

            if ($data['deputy'] ?? false) {
                [$dpm] = PositionComposer::findOrCreate(['work_location_id' => $workLocation->id, 'job_level_id' => $dpmSub->id]);
                $dpm->update(['parent_position_id' => $pm->id]);
                self::fill($dpm, $data['dpm_employee_id'] ?? null);
            }

            return self::linkRoots($workLocation, $pm->fresh('jobLevel'));
        });

        Inertia::flash('success', "Struktur {$workLocation->name} dimulai dari Project Manager"
            .($linked ? "; {$linked} jabatan yang sudah ada dihubungkan ke bawahnya." : '.'));

        return back();
    }

    /** Changes made in the plan (add, move, remove positions; place Sub Lokasi boxes), all or nothing. */
    public function ops(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        abort_unless(AccessScope::allowsWorkLocation($workLocation->id), 403);
        $request->validate([
            'ops' => ['required', 'array', 'min:1', 'max:200'],
            'ops.*.type' => ['required', 'string', 'in:create,reparent,delete,sub_location'],
        ]);

        $count = DB::transaction(fn () => (new OrgChartChanges)->apply($request->input('ops')));
        Inertia::flash('success', $count === 1 ? 'Plan disimpan.' : "Plan disimpan ({$count} perubahan).");

        return back();
    }

    /** Positions here without a superior report to the Project Manager. */
    public function link(WorkLocation $workLocation): RedirectResponse
    {
        abort_unless(AccessScope::allowsWorkLocation($workLocation->id), 403);
        $pm = SitePlan::pmOf($workLocation)?->load('jobLevel')
            ?? throw ValidationException::withMessages(['ops' => "{$workLocation->name} belum punya Project Manager."]);

        $n = DB::transaction(fn () => self::linkRoots($workLocation, $pm));
        Inertia::flash('success', $n ? "{$n} jabatan dihubungkan ke {$pm->name}." : 'Tidak ada jabatan yang perlu dihubungkan.');

        return back();
    }

    /** The work location shown: ?lokasi=, else the first one (of those the account may open). */
    public static function chosenLocation(Request $request): ?WorkLocation
    {
        $allowed = AccessScope::workLocationIds();
        $query = fn () => WorkLocation::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed));

        return $query()->find($request->integer('location')) ?? $query()->orderBy('sort_order')->first();
    }

    /** @return list<array<string, mixed>> the location tabs */
    public static function locations(): array
    {
        $positions = Position::selectRaw('work_location_id, COUNT(*) as n')->groupBy('work_location_id')->pluck('n', 'work_location_id');
        $people = Employee::where('status', '!=', Employee::STATUS_TERMINATED)->selectRaw('work_location_id, COUNT(*) as n, SUM(CASE WHEN position_id IS NULL THEN 1 ELSE 0 END) as waiting')
            ->groupBy('work_location_id')->get()->keyBy('work_location_id');

        $allowed = AccessScope::workLocationIds();

        return WorkLocation::orderBy('sort_order')->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->get()->map(fn (WorkLocation $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'code' => $w->code,
            'type' => $w->type,
            'position_count' => (int) ($positions[$w->id] ?? 0),
            'people_count' => (int) ($people[$w->id]->n ?? 0),
            'waiting_count' => (int) ($people[$w->id]->waiting ?? 0),
            'needs_setup' => ! $w->isHeadOffice() && ! SitePlan::pmOf($w),
        ])->values()->all();
    }

    private static function fill(Position $position, ?int $employeeId): void
    {
        if (! $employeeId) {
            return; // vacant
        }
        $employee = Employee::findOrFail($employeeId);
        if ($employee->status === Employee::STATUS_TERMINATED) {
            throw ValidationException::withMessages(['setup' => "{$employee->name} sudah keluar."]);
        }
        $employee->update([...PositionComposer::employeePlacementOf($position), 'position_id' => $position->id, 'status' => Employee::STATUS_ACTIVE]);
    }

    /** Positions at the work location itself without a superior go under the head, layer permitting. */
    private static function linkRoots(WorkLocation $location, Position $pm): int
    {
        $roots = Position::with('jobLevel')->where('work_location_id', $location->id)
            ->whereNull('sub_location_id')->whereNull('parent_position_id')->where('id', '!=', $pm->id)->get()
            ->reject(fn (Position $j) => $j->superiorLayerError($pm) !== null);
        Position::whereIn('id', $roots->pluck('id'))->update(['parent_position_id' => $pm->id]);

        return $roots->count();
    }
}
