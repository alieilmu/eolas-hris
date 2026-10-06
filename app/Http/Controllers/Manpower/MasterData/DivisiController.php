<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Divisi;
use App\Support\Org\JabatanComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DivisiController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $divisi = Divisi::create([...$this->validated($request), 'order' => Divisi::max('order') + 1]);
        Inertia::flash('success', "Divisi {$divisi->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Divisi $divisi): RedirectResponse
    {
        $divisi->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = JabatanComposer::refreshNames();
        Inertia::flash('success', 'Divisi diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Divisi $divisi): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $divisi->departemens()->count()) ? "{$n} departemen" : null,
            ($n = $divisi->jabatans()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Divisi {$divisi->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan atau hapus dulu.']);
        }

        $divisi->delete();
        Inertia::flash('success', "Divisi {$divisi->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'bod_id' => ['required', 'integer', 'exists:bods,id'],
        ], [], ['name' => 'Nama divisi', 'bod_id' => 'BOD']);
    }
}
