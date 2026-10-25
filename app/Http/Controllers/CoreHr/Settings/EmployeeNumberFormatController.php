<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeNumberFormat;
use App\Models\PointOfHire;
use App\Support\Employees\EmployeeNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Pengaturan Core HR → Format Nomor Induk. Existing NRPs are never rewritten: a number
 * that does not fit a new format only stops showing what is read from it.
 */
class EmployeeNumberFormatController extends Controller
{
    /** What a page needs to show and edit the format. */
    public static function props(): array
    {
        $f = EmployeeNumberFormat::current();
        $length = fn (string $type) => EmployeeNumber::segmentLength($type);

        return [
            'mode' => $f->mode,
            'segments' => $f->segments,
            'max_length' => $f->max_length,
            'segment_types' => EmployeeNumberFormat::SEGMENT_TYPES,
            'describe' => EmployeeNumber::describe(),
            'example' => EmployeeNumber::example(),
            'length' => EmployeeNumber::length(),
            // Codes and employees that do not fit the format (after it was changed).
            'mismatch' => [
                'category' => $length('category') ? EmployeeCategory::pluck('code')->filter(fn ($c) => strlen($c) !== $length('category'))->values() : [],
                'point_of_hire' => $length('point_of_hire') ? PointOfHire::pluck('code')->filter(fn ($c) => strlen($c) !== $length('point_of_hire'))->values() : [],
                'employees' => $f->mode === 'free' ? 0 : Employee::withoutGlobalScope('access')->pluck('employee_number')->filter(fn ($n) => EmployeeNumber::parse((string) $n) === null)->count(),
            ],
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['pattern', 'free'])],
            'max_length' => ['required_if:mode,free', 'nullable', 'integer', 'min:3', 'max:20'],
            'segments' => ['required_if:mode,pattern', 'array', 'max:8'],
            'segments.*.type' => ['required', Rule::in(array_keys(EmployeeNumberFormat::SEGMENT_TYPES))],
            'segments.*.value' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'segments.*.length' => ['nullable', 'integer', 'min:1', 'max:8'],
        ], ['segments.*.value.regex' => 'Teks tetap hanya huruf dan angka, tanpa spasi.'], ['max_length' => 'Panjang maksimal']);

        $segments = [];
        if ($data['mode'] === 'pattern') {
            $segments = array_values(array_map(fn (array $s) => $s['type'] === 'text'
                ? ['type' => 'text', 'value' => strtoupper((string) ($s['value'] ?? ''))]
                : ['type' => $s['type'], 'length' => (int) ($s['length'] ?? 0)], $data['segments'] ?? []));
            $this->check($segments);
        }

        EmployeeNumberFormat::current()->update([
            'mode' => $data['mode'],
            'segments' => $data['mode'] === 'pattern' ? $segments : EmployeeNumberFormat::current()->segments,
            'max_length' => (int) ($data['max_length'] ?? 20),
        ]);
        Inertia::flash('success', 'Format Nomor Induk disimpan: '.EmployeeNumber::describe().'.');

        return back();
    }

    /** A pattern must end with the nomor urut, have each kind of code at most once, and fit in 20 characters. */
    private function check(array $segments): void
    {
        $types = array_column($segments, 'type');
        $fail = fn (string $m) => throw ValidationException::withMessages(['segments' => $m]);
        if (end($types) !== 'sequence' || count(array_keys($types, 'sequence')) !== 1) {
            $fail('Format harus diakhiri satu bagian Nomor urut.');
        }
        foreach (['category', 'point_of_hire', 'year'] as $t) {
            if (count(array_keys($types, $t)) > 1) {
                $fail(EmployeeNumberFormat::SEGMENT_TYPES[$t].' hanya boleh sekali.');
            }
        }
        $total = 0;
        foreach ($segments as $s) {
            $len = $s['type'] === 'text' ? strlen($s['value']) : $s['length'];
            match (true) {
                $s['type'] === 'text' && $len === 0 => $fail('Teks tetap tidak boleh kosong.'),
                $s['type'] === 'year' && ! in_array($len, [2, 4], true) => $fail('Tahun masuk 2 digit (24) atau 4 digit (2024).'),
                in_array($s['type'], ['category', 'point_of_hire'], true) && ($len < 1 || $len > 4) => $fail(EmployeeNumberFormat::SEGMENT_TYPES[$s['type']].' 1–4 karakter.'),
                $s['type'] === 'sequence' && ($len < 2 || $len > 8) => $fail('Nomor urut 2–8 digit.'),
                default => null,
            };
            $total += $len;
        }
        if ($total > 20) {
            $fail("Panjang NRP {$total} karakter; maksimal 20.");
        }
    }
}
