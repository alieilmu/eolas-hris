<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Bod;
use App\Support\Org\JabatanComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Board of directors: the top of the organisation chart. */
class BodController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $bod = Bod::create([...$this->validated($request), 'order' => Bod::max('order') + 1]);
        Inertia::flash('success', "BOD {$bod->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Bod $bod): RedirectResponse
    {
        $bod->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = JabatanComposer::refreshNames();
        Inertia::flash('success', 'BOD diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Bod $bod): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $bod->divisis()->count()) ? "{$n} divisi" : null,
            ($n = $bod->jabatans()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "BOD {$bod->name} masih membawahi ".implode(' dan ', $blockers).'. Pindahkan dulu.']);
        }

        $bod->delete();
        Inertia::flash('success', "BOD {$bod->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [], ['name' => 'Nama BOD']);
    }
}
