<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Directorate;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Board of directors: the top of the organisation chart. */
class DirectorateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $directorate = Directorate::create([...$this->validated($request), 'sort_order' => Directorate::max('sort_order') + 1]);
        Inertia::flash('success', "BOD {$directorate->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Directorate $directorate): RedirectResponse
    {
        $directorate->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'BOD diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Directorate $directorate): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $directorate->divisions()->count()) ? "{$n} divisi" : null,
            ($n = $directorate->positions()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "BOD {$directorate->name} masih membawahi ".implode(' dan ', $blockers).'. Pindahkan dulu.']);
        }

        $directorate->delete();
        Inertia::flash('success', "BOD {$directorate->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [], ['name' => 'Nama BOD']);
    }
}
