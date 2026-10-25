<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Support\Organization\PositionComposer;
use App\Support\Organization\PlacementRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Positions. Their names are composed from a placement (work location +
 * SubGroup + unit or bidang), so a position is added — e.g. a vacancy before
 * anyone fills it — or removed, never renamed by hand. Placing an employee
 * creates the position as needed as well.
 */
class PositionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $placement = $request->validate(PlacementRules::rules(), [], PlacementRules::attributes());

        [$attributes, $error] = PositionComposer::compose($placement);
        if (! $attributes) {
            throw ValidationException::withMessages(['placement' => $error]);
        }
        [$position] = PositionComposer::findOrCreate($placement);
        if (! $position->wasRecentlyCreated) {
            throw ValidationException::withMessages(['placement' => "Jabatan \"{$position->name}\" sudah ada di {$position->workLocation->name}."]);
        }

        Inertia::flash('success', "Jabatan {$position->name} ditambahkan.");

        return back();
    }

    public function destroy(Position $position): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $position->employees()->count()) ? "{$n} karyawan (termasuk yang sudah keluar)" : null,
            ($n = $position->children()->count()) ? "{$n} jabatan bawahan di bagan organisasi" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Jabatan {$position->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan dulu sebelum menghapus.']);
        }

        $position->delete();
        Inertia::flash('success', "Jabatan {$position->name} dihapus.");

        return back();
    }
}
