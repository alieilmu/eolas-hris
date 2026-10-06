<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Jabatan;
use App\Models\SubGroup;
use App\Support\Org\JabatanComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Employee subgroups: a layer 1–6 (higher = more senior) and the rule that
 * names their positions — changing the rule renames the positions.
 */
class SubGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $subGroup = SubGroup::create($this->validated($request));
        Inertia::flash('success', "SubGroup {$subGroup->nama} (layer {$subGroup->layer}) ditambahkan.");

        return back();
    }

    public function update(Request $request, SubGroup $subGroup): RedirectResponse
    {
        $data = $this->validated($request, $subGroup);
        if ((int) $data['layer'] !== $subGroup->layer && $error = $this->layerConflict($subGroup, (int) $data['layer'])) {
            throw ValidationException::withMessages(['layer' => $error]);
        }
        $subGroup->update($data);
        $renamed = JabatanComposer::refreshNames();
        Inertia::flash('success', 'SubGroup diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(SubGroup $subGroup): RedirectResponse
    {
        if ($n = $subGroup->jabatans()->count()) {
            throw ValidationException::withMessages(['nama' => "SubGroup {$subGroup->nama} masih dipakai {$n} jabatan. Ganti SubGroup jabatan tersebut dulu."]);
        }

        $subGroup->delete();
        Inertia::flash('success', "SubGroup {$subGroup->nama} dihapus.");

        return back();
    }

    private function validated(Request $request, ?SubGroup $current = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100', Rule::unique('sub_groups', 'nama')->ignore($current)],
            'layer' => ['required', 'integer', 'between:'.SubGroup::MIN_LAYER.','.SubGroup::MAX_LAYER],
            'sumber' => ['required', Rule::in(array_keys(SubGroup::SUMBER))],
            'gelar' => ['nullable', 'string', 'max:60'],
            'gelar_ho' => ['nullable', 'string', 'max:60'],
        ], [
            'nama.unique' => 'SubGroup ":input" sudah ada.',
            'layer.between' => 'Layer harus antara '.SubGroup::MIN_LAYER.' dan '.SubGroup::MAX_LAYER.' (makin tinggi makin senior).',
        ], [
            'nama' => 'Nama SubGroup',
            'layer' => 'Layer',
            'sumber' => 'Nama diambil dari',
            'gelar' => 'Gelar',
            'gelar_ho' => 'Gelar di Head Office',
        ]);

        // Titles are written in capitals, like the rest of the position names.
        foreach (['gelar', 'gelar_ho'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? mb_strtoupper(trim($data[$key])) : null;
        }

        return $data;
    }

    /** A reporting line in the chart that the new layer would turn upside down. */
    private function layerConflict(SubGroup $subGroup, int $layer): ?string
    {
        $jabatans = Jabatan::with(['parent.subGroup', 'children.subGroup'])->where('sub_group_id', $subGroup->id)->get();
        foreach ($jabatans as $j) {
            if ($j->parent && $j->parent->sub_group_id !== $subGroup->id && ($j->parent->subGroup?->layer ?? $layer) < $layer) {
                return "Layer {$layer} membuat {$j->name} lebih tinggi dari atasannya {$j->parent->name} (layer {$j->parent->subGroup->layer}). Ubah atasannya di Struktur Organisasi dulu.";
            }
            $child = $j->children->first(fn (Jabatan $c) => $c->sub_group_id !== $subGroup->id && ($c->subGroup?->layer ?? 0) > $layer);
            if ($child) {
                return "Layer {$layer} membuat {$j->name} lebih rendah dari bawahannya {$child->name} (layer {$child->subGroup->layer}). Ubah atasannya di Struktur Organisasi dulu.";
            }
        }

        return null;
    }
}
