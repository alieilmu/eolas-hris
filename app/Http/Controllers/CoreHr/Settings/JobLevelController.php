<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\JobLevel;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Employee subgroups: a layer 1–6 (higher = more senior) and the rule that
 * names their positions — changing the rule renames the positions.
 */
class JobLevelController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $jobLevel = JobLevel::create($this->validated($request));
        Inertia::flash('success', "SubGroup {$jobLevel->name} (layer {$jobLevel->layer}) ditambahkan.");

        return back();
    }

    public function update(Request $request, JobLevel $jobLevel): RedirectResponse
    {
        $data = $this->validated($request, $jobLevel);
        if ((int) $data['layer'] !== $jobLevel->layer && $error = $this->layerConflict($jobLevel, (int) $data['layer'])) {
            throw ValidationException::withMessages(['layer' => $error]);
        }
        $jobLevel->update($data);
        $renamed = PositionComposer::refreshNames();
        Inertia::flash('success', 'SubGroup diperbarui'.($renamed ? " — {$renamed} nama jabatan ikut disesuaikan." : '.'));

        return back();
    }

    public function destroy(JobLevel $jobLevel): RedirectResponse
    {
        if ($n = $jobLevel->positions()->count()) {
            throw ValidationException::withMessages(['name' => "SubGroup {$jobLevel->name} masih dipakai {$n} jabatan. Ganti SubGroup jabatan tersebut dulu."]);
        }

        $jobLevel->delete();
        Inertia::flash('success', "SubGroup {$jobLevel->name} dihapus.");

        return back();
    }

    private function validated(Request $request, ?JobLevel $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('job_levels', 'name')->ignore($current)],
            'layer' => ['required', 'integer', 'between:'.JobLevel::MIN_LAYER.','.JobLevel::MAX_LAYER],
            'source' => ['required', Rule::in(array_keys(JobLevel::SUMBER))],
            'title_prefix' => ['nullable', 'string', 'max:60'],
            'title_prefix_head_office' => ['nullable', 'string', 'max:60'],
        ], [
            'name.unique' => 'SubGroup ":input" sudah ada.',
            'layer.between' => 'Layer harus antara '.JobLevel::MIN_LAYER.' dan '.JobLevel::MAX_LAYER.' (makin tinggi makin senior).',
        ], [
            'name' => 'Nama SubGroup',
            'layer' => 'Layer',
            'source' => 'Nama diambil dari',
            'title_prefix' => 'Gelar',
            'title_prefix_head_office' => 'Gelar di Head Office',
        ]);

        // Titles are written in capitals, like the rest of the position names.
        foreach (['title_prefix', 'title_prefix_head_office'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? mb_strtoupper(trim($data[$key])) : null;
        }

        return $data;
    }

    /** A reporting line in the chart that the new layer would turn upside down. */
    private function layerConflict(JobLevel $jobLevel, int $layer): ?string
    {
        $positions = Position::with(['parent.jobLevel', 'children.jobLevel'])->where('job_level_id', $jobLevel->id)->get();
        foreach ($positions as $j) {
            if ($j->parent && $j->parent->job_level_id !== $jobLevel->id && ($j->parent->jobLevel?->layer ?? $layer) < $layer) {
                return "Layer {$layer} membuat {$j->name} lebih tinggi dari atasannya {$j->parent->name} (layer {$j->parent->jobLevel->layer}). Ubah atasannya di Struktur Organisasi dulu.";
            }
            $child = $j->children->first(fn (Position $c) => $c->job_level_id !== $jobLevel->id && ($c->jobLevel?->layer ?? 0) > $layer);
            if ($child) {
                return "Layer {$layer} membuat {$j->name} lebih rendah dari bawahannya {$child->name} (layer {$child->jobLevel->layer}). Ubah atasannya di Struktur Organisasi dulu.";
            }
        }

        return null;
    }
}
