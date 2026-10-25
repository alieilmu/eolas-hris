<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Sections: the organisation level under Departemen. */
class SectionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $section = Section::create([...$this->validated($request), 'sort_order' => Section::max('sort_order') + 1]);
        Inertia::flash('success', "Section {$section->name} ditambahkan.");

        return back();
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $section->update($this->validated($request, $section));
        // Position names carry unit and place names.
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'Section diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(Section $section): RedirectResponse
    {
        if ($n = $section->positions()->count()) {
            throw ValidationException::withMessages(['name' => "Section {$section->name} masih punya {$n} jabatan. Pindahkan atau hapus jabatannya dulu."]);
        }

        $section->delete();
        Inertia::flash('success', "Section {$section->name} dihapus.");

        return back();
    }

    private function validated(Request $request, ?Section $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ], [], ['name' => 'Nama section', 'department_id' => 'Departemen']);

        $taken = Section::where('department_id', $data['department_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Section \"{$data['name']}\" sudah ada di departemen ini."]);
        }

        return $data;
    }
}
