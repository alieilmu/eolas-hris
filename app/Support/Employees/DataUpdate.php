<?php

namespace App\Support\Employees;

use App\Http\Requests\CoreHr\UpdateEmployeeProfileRequest;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Update Data: which parts of the profile an employee may ask to change
 * (personal data, documents, address, contacts, family — not placement, contract,
 * golongan or employment status), the difference with what is stored, and applying
 * it once HR approves.
 */
class DataUpdate
{
    /** Profile sections an employee fills in themselves. */
    public const SECTIONS = [
        'employment', 'uniform', 'identification', 'bank_account', 'personal', 'education_level', 'residential_address',
        'mailing_address', 'contact', 'email', 'spouse', 'children', 'parents', 'in_laws',
    ];

    /** Kept by HR even inside those sections. */
    public const HR_ONLY = ['employment_status'];

    public const ANAK_FIELDS = ['name', 'birth_date', 'education_level', 'employment_status', 'marital_status'];

    /** The sections as the portal form shows them. */
    public static function sections(): array
    {
        return collect(ProfileSections::build())
            ->filter(fn (array $s) => in_array($s['id'], self::SECTIONS, true))
            ->map(function (array $s) {
                if (isset($s['fields'])) {
                    $s['fields'] = array_values(array_filter($s['fields'], fn (array $f) => ! ($f['computed'] ?? false) && ! in_array($f['key'], self::HR_ONLY, true)));
                }

                return $s;
            })
            ->values()
            ->all();
    }

    /** key => field definition of everything an employee may change. */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::sections() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field + ['section' => $section['title']];
            }
        }

        return $fields;
    }

    /** The stored values of those fields and the children, for the form. */
    public static function current(Employee $k): array
    {
        $k->loadMissing('profile.children');
        $stored = $k->profile?->attributesToArray() ?? [];

        return [
            'profile' => array_map(fn (string $key) => $stored[$key] ?? null, array_combine(array_keys(self::fields()), array_keys(self::fields()))),
            'children' => ($k->profile?->children ?? collect())->map(fn (EmployeeChild $a) => self::childRow($a))->values()->all(),
        ];
    }

    /**
     * The fields that differ from what is stored and the children added, changed or removed.
     *
     * @return array{0: array<string, array{before: mixed, after: mixed}>, 1: list<array>}
     */
    public static function diff(Request $request, Employee $k): array
    {
        $fields = self::fields();
        $form = new UpdateEmployeeProfileRequest;
        $rules = array_intersect_key($form->rules(), $fields);
        $rules += [
            'children' => ['nullable', 'array', 'max:20'],
            'children.*.id' => ['nullable', 'integer'],
            'children.*.delete' => ['nullable', 'boolean'],
            'children.*.name' => ['nullable', 'string', 'max:255', 'required_unless:children.*.delete,1,true'],
            'children.*.birth_date' => ['nullable', 'date_format:Y-m-d'],
            'children.*.education_level' => ['nullable', Rule::in(LookupValues::values('child_education_level'))],
            'children.*.employment_status' => ['nullable', Rule::in(ProfileSections::STATUS_BEKERJA_ANAK)],
            'children.*.marital_status' => ['nullable', Rule::in(ProfileSections::STATUS_MENIKAH_ANAK)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
        $data = Validator::make($request->all(), $rules, $form->messages() + ['children.*.name.required_unless' => 'Nama anak wajib diisi.'], $form->attributes() + [
            'children.*.name' => 'Nama anak', 'children.*.birth_date' => 'Tanggal lahir anak', 'children.*.education_level' => 'Pendidikan anak',
        ])->validate();

        $current = self::current($k);
        $changes = [];
        foreach ($fields as $key => $field) {
            if (! $request->exists($key)) {
                continue;
            }
            $after = self::clean($data[$key] ?? null);
            $before = $current['profile'][$key];
            if ((string) $after !== (string) $before) {
                $changes[$key] = ['before' => $before, 'after' => $after];
            }
        }
        foreach (ProfileRules::NUMBERED_FIELDS as $key => $label) {
            if (isset($changes[$key]) && ($error = ProfileRules::duplicateNumberError([$key => $changes[$key]['after']], $k->id))) {
                throw ValidationException::withMessages([$key => $error]);
            }
        }

        $existing = collect($current['children'])->keyBy('id');
        $children = [];
        foreach ($data['children'] ?? [] as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id && ! $existing->has($id)) {
                throw ValidationException::withMessages(['children' => 'Data anak tidak ditemukan; muat ulang halaman.']);
            }
            $after = [];
            foreach (self::ANAK_FIELDS as $f) {
                $after[$f] = self::clean($row[$f] ?? null);
            }
            if (! $id) {
                if (empty($row['delete']) && $after['name']) {
                    $children[] = ['action' => 'add', 'child_id' => null, 'before' => null, 'after' => $after];
                }
            } elseif (! empty($row['delete'])) {
                $children[] = ['action' => 'delete', 'child_id' => $id, 'before' => $existing[$id], 'after' => null];
            } else {
                $before = array_intersect_key($existing[$id], array_flip(self::ANAK_FIELDS));
                if (array_map('strval', $before) !== array_map('strval', $after)) {
                    $children[] = ['action' => 'edit', 'child_id' => $id, 'before' => $before, 'after' => $after];
                }
            }
        }

        if (! $changes && ! $children) {
            throw ValidationException::withMessages(['changes' => 'Belum ada data yang diubah.']);
        }

        return [$changes, $children];
    }

    /** Writes an approved request into the profile and the children. */
    public static function apply(EmployeeChangeRequest $p): void
    {
        $k = $p->employee;
        $after = array_map(fn (array $c) => $c['after'], $p->changes ?? []);
        if ($error = ProfileRules::duplicateNumberError($after, $k->id)) {
            throw ValidationException::withMessages(['decision' => $error]);
        }

        DB::transaction(function () use ($k, $after, $p) {
            $profile = $k->profile()->firstOrCreate();
            if ($after) {
                $profile->update($after);
            }
            foreach ($p->children ?? [] as $op) {
                $child = $op['child_id'] ? $profile->children()->find($op['child_id']) : null;
                match ($op['action']) {
                    'add' => $profile->children()->create([...$op['after'], 'sort_order' => $profile->children()->count() + 1]),
                    'edit' => $child?->update($op['after']),
                    'delete' => $child?->delete(),
                    default => null,
                };
            }
            // Keep "Anak ke-N" contiguous.
            foreach ($profile->children()->get()->values() as $i => $child) {
                if ($child->sort_order !== $i + 1) {
                    $child->update(['sort_order' => $i + 1]);
                }
            }
        });
    }

    public static function childRow(EmployeeChild $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'birth_date' => $a->birth_date?->toDateString(),
            'education_level' => $a->education_level,
            'employment_status' => $a->employment_status,
            'marital_status' => $a->marital_status,
        ];
    }

    /** A request as listed, for HR and for the employee. */
    public static function row(EmployeeChangeRequest $p): array
    {
        return [
            'id' => $p->id,
            'employee_id' => $p->employee_id,
            'employee_number' => $p->employee?->employee_number,
            'name' => $p->employee?->name,
            'position' => $p->employee?->position?->name,
            'work_location' => $p->employee?->workLocation?->name,
            'changes' => $p->changes ?? [],
            'children' => $p->children ?? [],
            'quantity' => count($p->changes ?? []) + count($p->children ?? []),
            'notes' => $p->notes,
            'status' => $p->status,
            'verification_notes' => $p->verification_notes,
            'verifier' => $p->verifier?->name,
            'verified_at' => $p->verified_at?->toDateTimeString(),
            'created_at' => $p->created_at?->toDateTimeString(),
            'attachment' => $p->attachments->map->toView()->values(),
        ];
    }

    private static function clean(mixed $v): mixed
    {
        if (is_string($v)) {
            $v = trim($v);
        }

        return $v === '' ? null : $v;
    }
}
