<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\LookupValue;
use App\Support\Employees\LookupValues;
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
class LookupValueController extends Controller
{
    public function store(Request $request, string $category): RedirectResponse
    {
        abort_unless(isset(LookupValues::CATEGORIES[$category]), 404);

        $data = $this->validated($request, $category);
        LookupValue::create([
            'category' => $category,
            'value' => $data['value'],
            'sort_order' => LookupValue::where('category', $category)->max('sort_order') + 1,
        ]);
        Inertia::flash('success', "\"{$data['value']}\" ditambahkan ke ".LookupValues::CATEGORIES[$category]['label'].'.');

        return back();
    }

    /** Many values at once, one per line (e.g. pasted from an Excel column); ones already listed are skipped. */
    public function storeMany(Request $request, string $category): RedirectResponse
    {
        abort_unless(isset(LookupValues::CATEGORIES[$category]), 404);

        $text = $request->validate(['values_bulk' => ['required', 'string', 'max:50000']], [], ['values_bulk' => 'Daftar nilai'])['values_bulk'];
        $existing = LookupValue::where('category', $category)->pluck('value')->map(fn ($v) => mb_strtolower($v))->flip();
        $sort_order = LookupValue::where('category', $category)->max('sort_order') ?? -1;

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
            LookupValue::create(['category' => $category, 'value' => $value, 'sort_order' => ++$sort_order]);
            $existing[mb_strtolower($value)] = true;
            $added++;
        }

        Inertia::flash('success', "{$added} nilai ditambahkan ke ".LookupValues::CATEGORIES[$category]['label'].($skipped ? " ({$skipped} sudah ada, dilewati)." : '.'));

        return back();
    }

    public function update(Request $request, LookupValue $option): RedirectResponse
    {
        $old = $option->value;
        $new = $this->validated($request, $option->category, $option)['value'];

        if ($new === $old) {
            return back();
        }
        if ($option->is_system) {
            throw ValidationException::withMessages(['value' => "\"{$old}\" dipakai aturan program (mis. kelengkapan data), jadi tidak bisa diganti namanya."]);
        }

        DB::transaction(function () use ($option, $old, $new) {
            $option->update(['value' => $new]);
            LookupValues::renameUsages($option->category, $old, $new);
        });

        Inertia::flash('success', "\"{$old}\" diganti menjadi \"{$new}\" — termasuk di data yang memakainya.");

        return back();
    }

    public function destroy(LookupValue $option): RedirectResponse
    {
        if ($option->is_system) {
            throw ValidationException::withMessages(['value' => "\"{$option->value}\" dipakai aturan program, jadi tidak bisa dihapus."]);
        }
        if ($used = LookupValues::usage($option->category, $option->value)) {
            throw ValidationException::withMessages(['value' => "\"{$option->value}\" masih dipakai {$used} data. Ganti dulu data tersebut, atau ubah namanya saja."]);
        }

        $option->delete();
        Inertia::flash('success', "\"{$option->value}\" dihapus.");

        return back();
    }

    /** Swaps the value with its neighbour above ("naik") or below ("turun"). */
    public function move(Request $request, LookupValue $option): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $ordered = LookupValue::where('category', $option->category)->orderBy('sort_order')->orderBy('id')->get()->all();
        $index = array_search($option->id, array_column($ordered, 'id'), true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if (! isset($ordered[$target])) {
            return back(); // already first / last
        }

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        // Renumber the whole list, so equal urutan values from older data cannot linger.
        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $item) {
                if ($item->sort_order !== $position) {
                    $item->update(['sort_order' => $position]);
                }
            }
        });

        return back();
    }

    private function validated(Request $request, string $category, ?LookupValue $current = null): array
    {
        return $request->validate([
            'value' => [
                'required', 'string', 'max:100',
                Rule::unique('lookup_values', 'value')->where('category', $category)->ignore($current),
            ],
        ], [
            'value.unique' => '":input" sudah ada di daftar ini.',
        ], ['value' => 'Nilai']);
    }
}
