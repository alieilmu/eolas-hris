<?php

namespace App\Support\Karyawan;

use App\Http\Requests\Manpower\UpdateKaryawanProfileRequest;
use App\Models\Karyawan;
use App\Models\KaryawanAnak;
use App\Models\PengajuanUpdateData;
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
        'status-pegawai', 'seragam', 'tanda-pengenal', 'rekening', 'pribadi', 'pendidikan', 'alamat-tinggal',
        'alamat-surat', 'kontak', 'email', 'pasangan', 'anak', 'orang-tua', 'mertua',
    ];

    /** Kept by HR even inside those sections. */
    public const HR_ONLY = ['status_pegawai'];

    public const ANAK_FIELDS = ['nama', 'tanggal_lahir', 'pendidikan', 'status_bekerja', 'status_menikah'];

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
    public static function current(Karyawan $k): array
    {
        $k->loadMissing('profile.anak');
        $stored = $k->profile?->attributesToArray() ?? [];

        return [
            'profile' => array_map(fn (string $key) => $stored[$key] ?? null, array_combine(array_keys(self::fields()), array_keys(self::fields()))),
            'anak' => ($k->profile?->anak ?? collect())->map(fn (KaryawanAnak $a) => self::anakRow($a))->values()->all(),
        ];
    }

    /**
     * The fields that differ from what is stored and the children added, changed or removed.
     *
     * @return array{0: array<string, array{before: mixed, after: mixed}>, 1: list<array>}
     */
    public static function diff(Request $request, Karyawan $k): array
    {
        $fields = self::fields();
        $form = new UpdateKaryawanProfileRequest;
        $rules = array_intersect_key($form->rules(), $fields);
        $rules += [
            'anak' => ['nullable', 'array', 'max:20'],
            'anak.*.id' => ['nullable', 'integer'],
            'anak.*.hapus' => ['nullable', 'boolean'],
            'anak.*.nama' => ['nullable', 'string', 'max:255', 'required_unless:anak.*.hapus,1,true'],
            'anak.*.tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
            'anak.*.pendidikan' => ['nullable', Rule::in(MasterOptions::values('pendidikan_anak'))],
            'anak.*.status_bekerja' => ['nullable', Rule::in(ProfileSections::STATUS_BEKERJA_ANAK)],
            'anak.*.status_menikah' => ['nullable', Rule::in(ProfileSections::STATUS_MENIKAH_ANAK)],
            'catatan' => ['nullable', 'string', 'max:500'],
        ];
        $data = Validator::make($request->all(), $rules, $form->messages() + ['anak.*.nama.required_unless' => 'Nama anak wajib diisi.'], $form->attributes() + [
            'anak.*.nama' => 'Nama anak', 'anak.*.tanggal_lahir' => 'Tanggal lahir anak', 'anak.*.pendidikan' => 'Pendidikan anak',
        ])->validate();

        $current = self::current($k);
        $perubahan = [];
        foreach ($fields as $key => $field) {
            if (! $request->exists($key)) {
                continue;
            }
            $after = self::clean($data[$key] ?? null);
            $before = $current['profile'][$key];
            if ((string) $after !== (string) $before) {
                $perubahan[$key] = ['before' => $before, 'after' => $after];
            }
        }
        foreach (ProfileRules::NUMBERED_FIELDS as $key => $label) {
            if (isset($perubahan[$key]) && ($error = ProfileRules::duplicateNumberError([$key => $perubahan[$key]['after']], $k->id))) {
                throw ValidationException::withMessages([$key => $error]);
            }
        }

        $existing = collect($current['anak'])->keyBy('id');
        $anak = [];
        foreach ($data['anak'] ?? [] as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id && ! $existing->has($id)) {
                throw ValidationException::withMessages(['anak' => 'Data anak tidak ditemukan; muat ulang halaman.']);
            }
            $after = [];
            foreach (self::ANAK_FIELDS as $f) {
                $after[$f] = self::clean($row[$f] ?? null);
            }
            if (! $id) {
                if (empty($row['hapus']) && $after['nama']) {
                    $anak[] = ['aksi' => 'tambah', 'anak_id' => null, 'before' => null, 'after' => $after];
                }
            } elseif (! empty($row['hapus'])) {
                $anak[] = ['aksi' => 'hapus', 'anak_id' => $id, 'before' => $existing[$id], 'after' => null];
            } else {
                $before = array_intersect_key($existing[$id], array_flip(self::ANAK_FIELDS));
                if (array_map('strval', $before) !== array_map('strval', $after)) {
                    $anak[] = ['aksi' => 'ubah', 'anak_id' => $id, 'before' => $before, 'after' => $after];
                }
            }
        }

        if (! $perubahan && ! $anak) {
            throw ValidationException::withMessages(['perubahan' => 'Belum ada data yang diubah.']);
        }

        return [$perubahan, $anak];
    }

    /** Writes an approved request into the profile and the children. */
    public static function apply(PengajuanUpdateData $p): void
    {
        $k = $p->karyawan;
        $after = array_map(fn (array $c) => $c['after'], $p->perubahan ?? []);
        if ($error = ProfileRules::duplicateNumberError($after, $k->id)) {
            throw ValidationException::withMessages(['keputusan' => $error]);
        }

        DB::transaction(function () use ($k, $after, $p) {
            $profile = $k->profile()->firstOrCreate();
            if ($after) {
                $profile->update($after);
            }
            foreach ($p->anak ?? [] as $op) {
                $child = $op['anak_id'] ? $profile->anak()->find($op['anak_id']) : null;
                match ($op['aksi']) {
                    'tambah' => $profile->anak()->create([...$op['after'], 'urutan' => $profile->anak()->count() + 1]),
                    'ubah' => $child?->update($op['after']),
                    'hapus' => $child?->delete(),
                    default => null,
                };
            }
            // Keep "Anak ke-N" contiguous.
            foreach ($profile->anak()->get()->values() as $i => $child) {
                if ($child->urutan !== $i + 1) {
                    $child->update(['urutan' => $i + 1]);
                }
            }
        });
    }

    public static function anakRow(KaryawanAnak $a): array
    {
        return [
            'id' => $a->id,
            'nama' => $a->nama,
            'tanggal_lahir' => $a->tanggal_lahir?->toDateString(),
            'pendidikan' => $a->pendidikan,
            'status_bekerja' => $a->status_bekerja,
            'status_menikah' => $a->status_menikah,
        ];
    }

    /** A request as listed, for HR and for the employee. */
    public static function row(PengajuanUpdateData $p): array
    {
        return [
            'id' => $p->id,
            'karyawan_id' => $p->karyawan_id,
            'nrp' => $p->karyawan?->nrp,
            'name' => $p->karyawan?->name,
            'jabatan' => $p->karyawan?->jabatan?->name,
            'work_location' => $p->karyawan?->workLocation?->name,
            'perubahan' => $p->perubahan ?? [],
            'anak' => $p->anak ?? [],
            'jumlah' => count($p->perubahan ?? []) + count($p->anak ?? []),
            'catatan' => $p->catatan,
            'status' => $p->status,
            'catatan_verifikasi' => $p->catatan_verifikasi,
            'verifier' => $p->verifier?->name,
            'verified_at' => $p->verified_at?->toDateTimeString(),
            'created_at' => $p->created_at?->toDateTimeString(),
            'lampiran' => $p->lampirans->map->toView()->values(),
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
