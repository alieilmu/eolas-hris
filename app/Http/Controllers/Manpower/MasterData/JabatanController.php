<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Jabatan;
use App\Support\Org\JabatanComposer;
use App\Support\Org\PenempatanRules;
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
class JabatanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $placement = $request->validate(PenempatanRules::rules(), [], PenempatanRules::attributes());

        [$attributes, $error] = JabatanComposer::compose($placement);
        if (! $attributes) {
            throw ValidationException::withMessages(['penempatan' => $error]);
        }
        [$jabatan] = JabatanComposer::findOrCreate($placement);
        if (! $jabatan->wasRecentlyCreated) {
            throw ValidationException::withMessages(['penempatan' => "Jabatan \"{$jabatan->name}\" sudah ada di {$jabatan->workLocation->name}."]);
        }

        Inertia::flash('success', "Jabatan {$jabatan->name} ditambahkan.");

        return back();
    }

    public function destroy(Jabatan $jabatan): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $jabatan->karyawans()->count()) ? "{$n} karyawan (termasuk yang sudah keluar)" : null,
            ($n = $jabatan->children()->count()) ? "{$n} jabatan bawahan di bagan organisasi" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Jabatan {$jabatan->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan dulu sebelum menghapus.']);
        }

        $jabatan->delete();
        Inertia::flash('success', "Jabatan {$jabatan->name} dihapus.");

        return back();
    }
}
