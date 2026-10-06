<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\MasterOption;
use App\Support\Karyawan\MasterOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Values of the choice lists. A rename follows through to every record that
 * uses the value; a value still in use, or one the program relies on, stays.
 */
class MasterOptionController extends Controller
{
    public function store(Request $request, string $kategori): RedirectResponse
    {
        abort_unless(isset(MasterOptions::CATEGORIES[$kategori]), 404);

        $data = $this->validated($request, $kategori);
        MasterOption::create([
            'kategori' => $kategori,
            'nilai' => $data['nilai'],
            'urutan' => MasterOption::where('kategori', $kategori)->max('urutan') + 1,
        ]);
        Inertia::flash('success', "\"{$data['nilai']}\" ditambahkan ke ".MasterOptions::CATEGORIES[$kategori]['label'].'.');

        return back();
    }

    /** Many values at once, one per line (e.g. pasted from an Excel column); ones already listed are skipped. */
    public function storeMany(Request $request, string $kategori): RedirectResponse
    {
        abort_unless(isset(MasterOptions::CATEGORIES[$kategori]), 404);

        $text = $request->validate(['nilai_banyak' => ['required', 'string', 'max:50000']], [], ['nilai_banyak' => 'Daftar nilai'])['nilai_banyak'];
        $existing = MasterOption::where('kategori', $kategori)->pluck('nilai')->map(fn ($v) => mb_strtolower($v))->flip();
        $urutan = MasterOption::where('kategori', $kategori)->max('urutan') ?? -1;

        $added = 0;
        $skipped = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $value = mb_substr(trim(preg_replace('/\s+/', ' ', $line)), 0, 100);
            if ($value === '') {
                continue;
            }
            if ($existing->has(mb_strtolower($value))) {
                $skipped++;

                continue;
            }
            MasterOption::create(['kategori' => $kategori, 'nilai' => $value, 'urutan' => ++$urutan]);
            $existing[mb_strtolower($value)] = true;
            $added++;
        }

        Inertia::flash('success', "{$added} nilai ditambahkan ke ".MasterOptions::CATEGORIES[$kategori]['label'].($skipped ? " ({$skipped} sudah ada, dilewati)." : '.'));

        return back();
    }

    public function update(Request $request, MasterOption $option): RedirectResponse
    {
        $old = $option->nilai;
        $new = $this->validated($request, $option->kategori, $option)['nilai'];

        if ($new === $old) {
            return back();
        }
        if ($option->sistem) {
            throw ValidationException::withMessages(['nilai' => "\"{$old}\" dipakai aturan program (mis. kelengkapan data), jadi tidak bisa diganti namanya."]);
        }

        DB::transaction(function () use ($option, $old, $new) {
            $option->update(['nilai' => $new]);
            MasterOptions::renameUsages($option->kategori, $old, $new);
        });

        Inertia::flash('success', "\"{$old}\" diganti menjadi \"{$new}\" — termasuk di data yang memakainya.");

        return back();
    }

    public function destroy(MasterOption $option): RedirectResponse
    {
        if ($option->sistem) {
            throw ValidationException::withMessages(['nilai' => "\"{$option->nilai}\" dipakai aturan program, jadi tidak bisa dihapus."]);
        }
        if ($used = MasterOptions::usage($option->kategori, $option->nilai)) {
            throw ValidationException::withMessages(['nilai' => "\"{$option->nilai}\" masih dipakai {$used} data. Ganti dulu data tersebut, atau ubah namanya saja."]);
        }

        $option->delete();
        Inertia::flash('success', "\"{$option->nilai}\" dihapus.");

        return back();
    }

    /** Swaps the value with its neighbour above ("naik") or below ("turun"). */
    public function move(Request $request, MasterOption $option): RedirectResponse
    {
        $direction = $request->validate(['arah' => ['required', Rule::in(['naik', 'turun'])]])['arah'];

        $ordered = MasterOption::where('kategori', $option->kategori)->orderBy('urutan')->orderBy('id')->get()->all();
        $index = array_search($option->id, array_column($ordered, 'id'), true);
        $target = $direction === 'naik' ? $index - 1 : $index + 1;

        if (! isset($ordered[$target])) {
            return back(); // already first / last
        }

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        // Renumber the whole list, so equal urutan values from older data cannot linger.
        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $item) {
                if ($item->urutan !== $position) {
                    $item->update(['urutan' => $position]);
                }
            }
        });

        return back();
    }

    private function validated(Request $request, string $kategori, ?MasterOption $current = null): array
    {
        return $request->validate([
            'nilai' => [
                'required', 'string', 'max:100',
                Rule::unique('master_options', 'nilai')->where('kategori', $kategori)->ignore($current),
            ],
        ], [
            'nilai.unique' => '":input" sudah ada di daftar ini.',
        ], ['nilai' => 'Nilai']);
    }
}
