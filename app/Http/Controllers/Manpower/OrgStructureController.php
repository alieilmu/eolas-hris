<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Karyawan\KaryawanFormOptions;
use App\Support\Org\SitePlan;
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
class OrgStructureController extends Controller
{
    public function index(Request $request): Response
    {
        $location = OrgPlanController::chosenLocation($request);
        $plan = $location ? new SitePlan($location) : null;
        $canEdit = $request->user()->canEdit('org-structure');
        $needsSetup = (bool) $plan?->needsSetup();

        return Inertia::render('Manpower/OrgStructure/Index', [
            'locations' => OrgPlanController::locations(),
            'location' => $location?->only(['id', 'name', 'kode', 'type']),
            'needs_setup' => $needsSetup,
            'setup' => $needsSetup && $canEdit ? OrgPlanController::setupProps() : null,
            'tree' => $plan && ! $needsSetup ? $plan->tree() : [],
            'unlinked_sites' => $plan?->unlinkedSites() ?? [],
            // Mode Organisasi: what can be added to the plan.
            'palette' => $canEdit && $plan && ! $needsSetup ? $plan->palette() : null,
            // Mode MPP: the people working here.
            'people' => $canEdit && $plan ? $plan->people() : [],
            'can_edit' => $canEdit,
            ...($canEdit ? KaryawanFormOptions::props() : []),
        ]);
    }

    /** "Mutasi": the positions and Sub Lokasi of a work location to move someone to. */
    public function positions(Request $request): JsonResponse
    {
        $location = WorkLocation::findOrFail($request->integer('lokasi'));
        abort_unless(AccessScope::allowsWorkLocation($location->id), 403);
        $holders = Karyawan::aktif()->whereNotNull('jabatan_id')->selectRaw('jabatan_id, COUNT(*) as n')->groupBy('jabatan_id')->pluck('n', 'jabatan_id');

        return response()->json([
            'positions' => Jabatan::with(['subGroup', 'subLocation', ...Jabatan::UNIT_RELATIONS])
                ->where('work_location_id', $location->id)->get()
                ->sortBy(fn (Jabatan $j) => [$j->subLocation?->name ?? '', -($j->subGroup?->layer ?? 0), $j->name])
                ->map(fn (Jabatan $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'sub_group_id' => $j->sub_group_id,
                    'sub_group' => $j->subGroup?->nama,
                    'layer' => $j->subGroup?->layer,
                    'unit_label' => $j->unitLevel() === Jabatan::NO_UNIT ? null : $j->unitLabel(),
                    'sub_location' => $j->subLocation?->name,
                    'holders' => (int) ($holders[$j->id] ?? 0),
                    'single_holder' => (bool) $j->subGroup?->isSingleHolder(),
                ])->values(),
            'sub_locations' => SubLocation::where('work_location_id', $location->id)->orderBy('order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
