<?php

namespace App\Support\Karyawan;

use App\Models\Bod;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Section;
use App\Models\SubGroup;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Org\JabatanComposer;
use Inertia\Inertia;
use Inertia\OptionalProp;

/** Choices for placing an employee (Tambah Karyawan, Ubah Penempatan), shared by every page that opens those dialogs. */
class KaryawanFormOptions
{
    /**
     * Only loaded when a dialog opens (it reloads these props by name).
     *
     * @return array<string, OptionalProp>
     */
    public static function props(): array
    {
        return [
            'penempatan_options' => Inertia::optional(fn () => self::penempatanOptions()),
            'nrp_codes' => Inertia::optional(fn () => self::nrpCodes()),
        ];
    }

    /** Everything the placement picker needs to compose a position name the way the server will. */
    public static function penempatanOptions(): array
    {
        return [
            'work_locations' => WorkLocation::orderBy('order')->get(['id', 'name', 'kode', 'type']),
            'sub_locations' => SubLocation::orderBy('work_location_id')->orderBy('order')->orderBy('name')->get(['id', 'name', 'work_location_id']),
            'sub_groups' => SubGroup::orderByDesc('layer')->orderBy('nama')
                ->get(['id', 'nama', 'layer', 'sumber', 'gelar', 'gelar_ho']),
            'bods' => Bod::orderBy('order')->get(['id', 'name']),
            'divisis' => Divisi::orderBy('order')->get(['id', 'name', 'bod_id']),
            'departemens' => Departemen::orderBy('divisi_id')->orderBy('order')->get(['id', 'name', 'divisi_id']),
            'sections' => Section::orderBy('departemen_id')->orderBy('order')->get(['id', 'name', 'departemen_id']),
            'bidang' => MasterOptions::values(JabatanComposer::BIDANG),
            // Existing positions with who holds them.
            'jabatans' => Jabatan::with(['karyawans' => fn ($q) => $q->aktif()->select('id', 'name', 'jabatan_id')])
                ->orderBy('order')->get(['id', 'name', 'sub_group_id', 'work_location_id'])
                ->map(fn (Jabatan $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'sub_group_id' => $j->sub_group_id,
                    'work_location_id' => $j->work_location_id,
                    'holders' => $j->karyawans->pluck('name'),
                ]),
        ];
    }

    /** What the dialog needs to build and explain an NRP. */
    public static function nrpCodes(): array
    {
        return [
            'jenis' => collect(Nrp::jenisMap())->map(fn ($nama, $kode) => ['kode' => (string) $kode, 'nama' => $nama])->values(),
            'lokasi' => collect(Nrp::lokasiMap())->map(fn ($l, $kode) => ['kode' => (string) $kode, ...$l])->values(),
            'max_urut' => Nrp::maxUrutPerPrefix(),
        ];
    }
}
