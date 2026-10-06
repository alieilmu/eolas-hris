<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Departemen;
use App\Support\Org\JabatanComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DepartemenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $departemen = Departemen::create([...$this->validated($request), 'order' => Departemen::max('order') + 1]);
        Inertia::flash('success', "Departemen {$departemen->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Departemen $departemen): RedirectResponse
    {
        $departemen->update($this->validated($request));
        // Position names carry unit and place names.
        $renamed = JabatanComposer::refreshNames();
        Inertia::flash('success', 'Departemen diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Departemen $departemen): RedirectResponse
    {
        $blockers = array_filter([
            ($n = $departemen->sections()->count()) ? "{$n} section" : null,
            ($n = $departemen->jabatans()->count()) ? "{$n} jabatan" : null,
        ]);
        if ($blockers) {
            throw ValidationException::withMessages(['name' => "Departemen {$departemen->name} masih punya ".implode(' dan ', $blockers).'. Pindahkan atau hapus dulu.']);
        }

        $departemen->delete();
        Inertia::flash('success', "Departemen {$departemen->name} dihapus.");

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'divisi_id' => ['required', 'integer', 'exists:divisis,id'],
        ], [], ['name' => 'Nama departemen', 'divisi_id' => 'Divisi']);
    }
}
