<?php

namespace App\Http\Controllers\Manpower\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Bod;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\JenisKaryawan;
use App\Models\Karyawan;
use App\Models\LokasiPenerimaan;
use App\Models\MasterOption;
use App\Models\Section;
use App\Models\SubGroup;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Karyawan\KaryawanFormOptions;
use App\Support\Karyawan\MasterOptions;
use App\Support\Org\OrgChart;
use Inertia\Inertia;
use Inertia\Response;

/** Master Data Personalia: every list the employee record draws from, on one page. */
class MasterDataController extends Controller
{
    public function index(): Response
    {
        $nrps = Karyawan::pluck('nrp');
        $countByPrefix = fn (int $start, int $length) => $nrps->countBy(fn (string $nrp) => substr($nrp, $start, $length));
        $jenisUse = $countByPrefix(0, 1);
        $lokasiUse = $countByPrefix(1, 2);

        return Inertia::render('Manpower/MasterData/Index', [
            'jenis' => JenisKaryawan::orderBy('kode')->get()->map(fn (JenisKaryawan $j) => [
                'id' => $j->id,
                'kode' => $j->kode,
                'nama' => $j->nama,
                'karyawan_count' => $jenisUse[$j->kode] ?? 0,
            ]),
            'lokasi' => LokasiPenerimaan::with('workLocation')->orderBy('kode')->get()->map(fn (LokasiPenerimaan $l) => [
                'id' => $l->id,
                'kode' => $l->kode,
                'nama' => $l->nama,
                'work_location_id' => $l->work_location_id,
                'karyawan_count' => $lokasiUse[$l->kode] ?? 0,
            ]),
            'work_locations' => WorkLocation::withCount(['karyawans', 'lokasiPenerimaans'])->orderBy('order')->get()->map(fn (WorkLocation $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'kode' => $w->kode,
                'type' => $w->type,
                'karyawan_count' => $w->karyawans_count,
                'lokasi_count' => $w->lokasi_penerimaans_count,
                'jabatan_count' => Jabatan::where('work_location_id', $w->id)->count(),
            ]),
            'bods' => Bod::withCount(['divisis', 'jabatans'])->orderBy('order')->get()->map(fn (Bod $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'divisi_count' => $b->divisis_count,
                'jabatan_count' => $b->jabatans_count,
            ]),
            // Highest layer first: the higher the layer, the more senior.
            'sub_groups' => SubGroup::withCount('jabatans')->orderByDesc('layer')->orderBy('nama')->get()->map(fn (SubGroup $g) => [
                'id' => $g->id,
                'nama' => $g->nama,
                'layer' => $g->layer,
                'sumber' => $g->sumber,
                'gelar' => $g->gelar,
                'gelar_ho' => $g->gelar_ho,
                'jabatan_count' => $g->jabatans_count,
            ]),
            'sumber_options' => SubGroup::SUMBER,
            'divisis' => Divisi::withCount(['departemens', 'jabatans'])->orderBy('order')->get()->map(fn (Divisi $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'bod_id' => $d->bod_id,
                'departemen_count' => $d->departemens_count,
                'jabatan_count' => $d->jabatans_count,
            ]),
            'departemens' => Departemen::withCount(['jabatans', 'sections'])->orderBy('divisi_id')->orderBy('order')->get()->map(fn (Departemen $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'divisi_id' => $d->divisi_id,
                'jabatan_count' => $d->jabatans_count,
                'section_count' => $d->sections_count,
            ]),
            'sub_locations' => SubLocation::withCount(['jabatans', 'karyawans'])->orderBy('work_location_id')->orderBy('order')->orderBy('name')->get()
                ->map(fn (SubLocation $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'work_location_id' => $s->work_location_id,
                    'jabatan_count' => $s->jabatans_count,
                    'karyawan_count' => $s->karyawans_count,
                ]),
            'sections' => Section::withCount('jabatans')->orderBy('departemen_id')->orderBy('order')->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'departemen_id' => $s->departemen_id,
                'jabatan_count' => $s->jabatans_count,
            ]),
            'jabatans' => Jabatan::with(['subGroup', 'workLocation', ...Jabatan::UNIT_RELATIONS])->withCount(['karyawans', 'children'])
                ->orderBy('order')->get()->map(fn (Jabatan $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'sub_group' => $j->subGroup?->nama,
                    'work_location' => $j->workLocation?->name,
                    'unit_label' => $j->unitLabel(),
                    'karyawan_count' => $j->karyawans_count,
                    'children_count' => $j->children_count,
                ]),
            'unit_options' => OrgChart::unitOptions(),
            // For "Tambah Jabatan": the same placement picker as for employees.
            'penempatan_options' => KaryawanFormOptions::penempatanOptions(),
            'options' => collect(MasterOptions::CATEGORIES)->map(fn (array $config, string $kategori) => [
                'kategori' => $kategori,
                'label' => $config['label'],
                'values' => MasterOption::where('kategori', $kategori)->orderBy('urutan')->orderBy('id')->get()
                    ->map(fn (MasterOption $o) => [
                        'id' => $o->id,
                        'nilai' => $o->nilai,
                        'sistem' => $o->sistem,
                        'usage' => MasterOptions::usage($kategori, $o->nilai),
                    ]),
            ])->values(),
        ]);
    }
}
