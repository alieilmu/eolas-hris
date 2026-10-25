<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Employee;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Organization\SitePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Struktur Organisasi, per work location, in two modes on one page (switched
 * without a reload, so both get their data at once): Mode Organisasi arranges
 * the positions (see OrgPlanController), Mode MPP fills them with the people
 * working there, listed by SubGroup and departemen. Every signed-in user can
 * read it; changing it is for Admin / HR (it is Man Power data).
 */
class OrgChartController extends Controller
{
    public function index(Request $request): Response
    {
        $location = OrgPlanController::chosenLocation($request);
        $plan = $location ? new SitePlan($location) : null;
        $canEdit = $request->user()->canEdit('org-chart');
        $needsSetup = (bool) $plan?->needsSetup();

        return Inertia::render('CoreHr/OrgChart/Index', [
            'locations' => OrgPlanController::locations(),
            'location' => $location?->only(['id', 'name', 'code', 'type']),
            'needs_setup' => $needsSetup,
            'setup' => $needsSetup && $canEdit ? OrgPlanController::setupProps() : null,
            'tree' => $plan && ! $needsSetup ? $plan->tree() : [],
            'unlinked_sites' => $plan?->unlinkedSites() ?? [],
            // Mode Organisasi: what can be added to the plan.
            'palette' => $canEdit && $plan && ! $needsSetup ? $plan->palette() : null,
            // Mode MPP: the people working here.
            'people' => $canEdit && $plan ? $plan->people() : [],
            'can_edit' => $canEdit,
            ...($canEdit ? EmployeeFormOptions::props() : []),
        ]);
    }

    /** "Mutasi": the positions and Sub Lokasi of a work location to move someone to. */
    public function positions(Request $request): JsonResponse
    {
        $location = WorkLocation::findOrFail($request->integer('location'));
        abort_unless(AccessScope::allowsWorkLocation($location->id), 403);
        $holders = Employee::active()->whereNotNull('position_id')->selectRaw('position_id, COUNT(*) as n')->groupBy('position_id')->pluck('n', 'position_id');

        return response()->json([
            'positions' => Position::with(['jobLevel', 'subLocation', ...Position::UNIT_RELATIONS])
                ->where('work_location_id', $location->id)->get()
                ->sortBy(fn (Position $j) => [$j->subLocation?->name ?? '', -($j->jobLevel?->layer ?? 0), $j->name])
                ->map(fn (Position $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'job_level_id' => $j->job_level_id,
                    'job_level' => $j->jobLevel?->name,
                    'layer' => $j->jobLevel?->layer,
                    'unit_label' => $j->unitLevel() === Position::NO_UNIT ? null : $j->unitLabel(),
                    'sub_location' => $j->subLocation?->name,
                    'holders' => (int) ($holders[$j->id] ?? 0),
                    'single_holder' => (bool) $j->jobLevel?->isSingleHolder(),
                ])->values(),
            'sub_locations' => SubLocation::where('work_location_id', $location->id)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
