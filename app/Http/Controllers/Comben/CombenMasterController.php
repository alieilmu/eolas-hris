<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\CutiAturan;
use App\Models\CutiLapangan;
use App\Models\Golongan;
use App\Models\LokasiPenerimaan;
use App\Models\LumpsumCuti;
use App\Models\PlafonKacamata;
use App\Models\PlafonPengobatan;
use App\Models\SkalaUpah;
use App\Models\SkalaUpahKolom;
use App\Models\SkalaUpahNilai;
use App\Models\SubGroup;
use App\Models\SubLocation;
use App\Models\TunjanganJabatan;
use App\Models\WorkLocation;
use App\Support\Karyawan\MasterOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Data Comben: Golongan per group (held by employees' profiles),
 * Struktur Skala Upah, Plafon Pengobatan and Plafon Kacamata.
 */
class CombenMasterController extends Controller
{
    private const PLAFON = ['pengobatan' => PlafonPengobatan::class, 'kacamata' => PlafonKacamata::class];

    public function index(): Response
    {
        $profiles = DB::table('karyawan_profiles')->whereNotNull('golongan')->select('golongan', DB::raw('count(*) as n'))->groupBy('golongan')->pluck('n', 'golongan');
        $plafons = [...PlafonPengobatan::all(), ...PlafonKacamata::all()];
        $golongans = Golongan::withCount('skalaUpahs')->orderBy('urutan')->orderBy('id')->get();

        return Inertia::render('Comben/MasterData', [
            'golongans' => $golongans->map(fn (Golongan $g) => [
                'id' => $g->id,
                'grup' => $g->grup,
                'kode' => $g->kode,
                'karyawan_count' => (int) ($profiles[$g->kode] ?? 0),
                'skala_count' => $g->skala_upahs_count,
                'plafon_count' => count(array_filter($plafons, fn ($p) => in_array($g->id, $p->golongan_ids, true))),
            ]),
            'grups' => array_values(array_unique([...Golongan::GRUP, ...$golongans->pluck('grup')->all()])),
            'kolom' => SkalaUpahKolom::with('workLocation')->orderBy('urutan')->orderBy('tahun')->get()->map(fn (SkalaUpahKolom $k) => [
                'id' => $k->id, 'tahun' => $k->tahun, 'work_location_id' => $k->work_location_id, 'label' => $k->label(),
            ]),
            'skala' => SkalaUpah::with(['golongan', 'nilai'])->get()
                ->sortBy([fn ($a, $b) => $a->golongan->urutan <=> $b->golongan->urutan, fn ($a, $b) => $a->urutan <=> $b->urutan])
                ->values()
                ->map(fn (SkalaUpah $s) => [
                    'id' => $s->id,
                    'golongan_id' => $s->golongan_id,
                    'kode' => $s->golongan->kode,
                    'grup' => $s->golongan->grup,
                    'step' => $s->step,
                    'nilai' => (object) $s->nilai->pluck('nominal', 'kolom_id')->all(),
                ]),
            'plafon_pengobatan' => PlafonPengobatan::orderBy('urutan')->orderBy('id')->get()->map(fn (PlafonPengobatan $p) => self::plafonRow($p) + ['lajang' => $p->lajang, 'keluarga' => $p->keluarga]),
            'plafon_kacamata' => PlafonKacamata::orderBy('urutan')->orderBy('id')->get()->map(fn (PlafonKacamata $p) => self::plafonRow($p) + ['bingkai' => $p->bingkai, 'lensa' => $p->lensa]),
            'sub_groups' => SubGroup::orderByDesc('layer')->orderBy('nama')->get(['id', 'nama', 'layer']),
            // Tunjangan Jabatan per SubGroup (fixed monthly allowance, part of the upah tetap).
            'tunjangan_jabatan' => (object) TunjanganJabatan::pluck('nominal', 'sub_group_id')->map(fn ($n) => (int) $n)->all(),
            'work_locations' => WorkLocation::orderBy('order')->get(['id', 'name']),
            // Cuti Tahunan & Besar.
            'cuti_aturan' => CutiAturan::current()->only(['jatah_tahunan', 'masa_aktif_tahun', 'besar_interval_tahun', 'besar_hari', 'besar_gapok_diambil', 'besar_gapok_diuangkan', 'besar_batas_bulan']),
            'cuti_lapangan' => CutiLapangan::with(['workLocation:id,name,order', 'subLocation:id,name'])->get()
                ->sortBy(fn (CutiLapangan $l) => sprintf('%05d-%s', $l->workLocation?->order ?? 0, $l->subLocation?->name ?? ''))
                ->values()
                ->map(fn (CutiLapangan $l) => [
                    'id' => $l->id,
                    'work_location_id' => $l->work_location_id,
                    'sub_location_id' => $l->sub_location_id,
                    'lokasi' => $l->workLocation?->name,
                    'sub_lokasi' => $l->subLocation?->name,
                    'hari_cuti' => $l->hari_cuti,
                    'hari_berangkat' => $l->hari_berangkat,
                    'hari_kembali' => $l->hari_kembali,
                    'total' => $l->total(),
                ]),
            'sub_locations' => SubLocation::orderBy('name')->get(['id', 'name', 'work_location_id']),
            // Lumpsum Cuti Lapangan: site × POH × klasifikasi.
            'lumpsum' => LumpsumCuti::get(['work_location_id', 'lokasi_penerimaan_id', 'klasifikasi', 'nominal']),
            'poh' => LokasiPenerimaan::orderBy('kode')->get(['id', 'kode', 'nama']),
            'klasifikasi' => MasterOptions::values('klasifikasi'),
        ]);
    }

    // --- Golongan ------------------------------------------------------------------------

    public function storeGolongan(Request $request): RedirectResponse
    {
        $data = $this->golonganData($request);
        // New codes go to the end of their group.
        $last = Golongan::where('grup', $data['grup'])->max('urutan') ?? Golongan::max('urutan') ?? -1;
        Golongan::where('urutan', '>', $last)->increment('urutan');
        $golongan = Golongan::create([...$data, 'urutan' => $last + 1]);
        Inertia::flash('success', "Golongan {$golongan->kode} ditambahkan ke {$golongan->grup}.");

        return back();
    }

    public function updateGolongan(Request $request, Golongan $golongan): RedirectResponse
    {
        $data = $this->golonganData($request, $golongan);
        $old = $golongan->kode;
        DB::transaction(function () use ($golongan, $data, $old) {
            $golongan->update($data);
            if ($old !== $golongan->kode) {
                // Employees hold the code: they follow the rename.
                DB::table('karyawan_profiles')->where('golongan', $old)->update(['golongan' => $golongan->kode]);
            }
        });
        Inertia::flash('success', "Golongan {$golongan->kode} diperbarui.");

        return back();
    }

    public function destroyGolongan(Golongan $golongan): RedirectResponse
    {
        $used = DB::table('karyawan_profiles')->where('golongan', $golongan->kode)->count();
        if ($used) {
            throw ValidationException::withMessages(['kode' => "Golongan {$golongan->kode} masih dipakai {$used} karyawan."]);
        }
        DB::transaction(function () use ($golongan) {
            foreach ([...PlafonPengobatan::all(), ...PlafonKacamata::all()] as $p) {
                if (in_array($golongan->id, $p->golongan_ids, true)) {
                    $p->update(['golongan_ids' => array_values(array_diff($p->golongan_ids, [$golongan->id]))]);
                }
            }
            $golongan->delete(); // its skala upah lines go with it
        });
        Inertia::flash('success', "Golongan {$golongan->kode} dihapus.");

        return back();
    }

    private function golonganData(Request $request, ?Golongan $current = null): array
    {
        $data = $request->validate([
            'grup' => ['required', 'string', 'max:30'],
            'kode' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/'],
        ], ['kode.regex' => 'Kode golongan hanya huruf, angka dan "-".'], ['grup' => 'Group', 'kode' => 'Kode golongan']);
        $data['grup'] = mb_strtoupper(trim($data['grup']));
        $data['kode'] = mb_strtoupper(trim($data['kode']));
        if (Golongan::whereRaw('UPPER(kode) = ?', [$data['kode']])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            throw ValidationException::withMessages(['kode' => "Golongan {$data['kode']} sudah ada."]);
        }

        return $data;
    }

    // --- Struktur Skala Upah ---------------------------------------------------------------

    public function storeSkala(Request $request): RedirectResponse
    {
        $data = $this->skalaData($request);
        DB::transaction(function () use ($data) {
            $skala = SkalaUpah::create(['golongan_id' => $data['golongan_id'], 'step' => $data['step'], 'urutan' => (SkalaUpah::max('urutan') ?? -1) + 1]);
            $this->saveNilai($skala, $data['nilai']);
        });
        Inertia::flash('success', 'Baris skala upah ditambahkan.');

        return back();
    }

    public function updateSkala(Request $request, SkalaUpah $skalaUpah): RedirectResponse
    {
        $data = $this->skalaData($request);
        DB::transaction(function () use ($skalaUpah, $data) {
            $skalaUpah->update(['golongan_id' => $data['golongan_id'], 'step' => $data['step']]);
            $this->saveNilai($skalaUpah, $data['nilai']);
        });
        Inertia::flash('success', 'Skala upah diperbarui.');

        return back();
    }

    public function destroySkala(SkalaUpah $skalaUpah): RedirectResponse
    {
        $skalaUpah->delete();
        Inertia::flash('success', 'Baris skala upah dihapus.');

        return back();
    }

    public function storeKolom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
        ], [], ['tahun' => 'Tahun', 'work_location_id' => 'Lokasi']);
        $taken = SkalaUpahKolom::where('tahun', $data['tahun'])
            ->when($data['work_location_id'] ?? null, fn ($q, $id) => $q->where('work_location_id', $id), fn ($q) => $q->whereNull('work_location_id'))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['tahun' => 'Kolom untuk tahun dan lokasi itu sudah ada.']);
        }
        $kolom = SkalaUpahKolom::create([...$data, 'urutan' => (SkalaUpahKolom::max('urutan') ?? -1) + 1]);
        Inertia::flash('success', "Kolom {$kolom->label()} ditambahkan.");

        return back();
    }

    public function destroyKolom(SkalaUpahKolom $kolom): RedirectResponse
    {
        $kolom->delete(); // its wages go with it
        Inertia::flash('success', "Kolom {$kolom->label()} dihapus.");

        return back();
    }

    private function skalaData(Request $request): array
    {
        $data = $request->validate([
            'golongan_id' => ['required', 'integer', 'exists:golongans,id'],
            'step' => ['nullable', 'string', 'max:20'],
            'nilai' => ['nullable', 'array'],
            'nilai.*' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
        ], [], ['golongan_id' => 'Golongan', 'nilai.*' => 'Nominal']);
        $data['step'] = filled($data['step'] ?? null) ? trim($data['step']) : null;
        $data['nilai'] ??= [];

        return $data;
    }

    private function saveNilai(SkalaUpah $skala, array $nilai): void
    {
        $kolom = SkalaUpahKolom::pluck('id')->flip();
        foreach ($nilai as $kolomId => $nominal) {
            if (! $kolom->has((int) $kolomId)) {
                continue;
            }
            if ($nominal === null || $nominal === '') {
                SkalaUpahNilai::where(['skala_upah_id' => $skala->id, 'kolom_id' => $kolomId])->delete();
            } else {
                SkalaUpahNilai::updateOrCreate(['skala_upah_id' => $skala->id, 'kolom_id' => (int) $kolomId], ['nominal' => (int) $nominal]);
            }
        }
    }

    // --- Plafon Pengobatan / Kacamata ------------------------------------------------------

    public function storePlafon(Request $request, string $jenis): RedirectResponse
    {
        $model = self::PLAFON[$jenis];
        $model::create([...$this->plafonData($request, $jenis), 'urutan' => ($model::max('urutan') ?? -1) + 1]);
        Inertia::flash('success', 'Baris plafon '.$jenis.' ditambahkan.');

        return back();
    }

    public function updatePlafon(Request $request, string $jenis, int $id): RedirectResponse
    {
        $plafon = self::PLAFON[$jenis]::findOrFail($id);
        $plafon->update($this->plafonData($request, $jenis, $plafon));
        Inertia::flash('success', "Plafon {$jenis} {$plafon->layer} diperbarui.");

        return back();
    }

    public function destroyPlafon(string $jenis, int $id): RedirectResponse
    {
        $plafon = self::PLAFON[$jenis]::findOrFail($id);
        $plafon->delete();
        Inertia::flash('success', "Plafon {$jenis} {$plafon->layer} dihapus.");

        return back();
    }

    private function plafonData(Request $request, string $jenis, ?Model $current = null): array
    {
        $amounts = $jenis === 'pengobatan' ? ['lajang' => 'Lajang', 'keluarga' => 'Keluarga'] : ['bingkai' => 'Bingkai', 'lensa' => 'Lensa'];
        $data = $request->validate([
            'layer' => ['required', 'string', 'max:10'],
            'golongan_ids' => ['present', 'array'],
            'golongan_ids.*' => ['integer', 'exists:golongans,id'],
            'sub_group_ids' => ['required', 'array', 'min:1'],
            'sub_group_ids.*' => ['integer', 'exists:sub_groups,id'],
            'jabatan_ho' => ['nullable', 'string', 'max:100'],
            'jabatan_site' => ['nullable', 'string', 'max:100'],
            ...array_map(fn () => ['required', 'integer', 'min:0', 'max:9999999999'], $amounts),
        ], ['sub_group_ids.required' => 'Pilih minimal satu SubGroup.'], ['layer' => 'Layer', 'sub_group_ids' => 'SubGroup', ...$amounts]);

        $data['golongan_ids'] = array_values(array_unique(array_map('intval', $data['golongan_ids'])));
        $data['sub_group_ids'] = array_values(array_unique(array_map('intval', $data['sub_group_ids'])));
        $data['layer'] = mb_strtoupper(trim($data['layer']));

        // A SubGroup belongs to one plafon row, so an employee's plafon is never ambiguous.
        $others = self::PLAFON[$jenis]::when($current, fn ($q) => $q->whereKeyNot($current->id))->get();
        $taken = [];
        foreach ($others as $other) {
            foreach (array_intersect($data['sub_group_ids'], $other->sub_group_ids) as $id) {
                $taken[] = SubGroup::find($id)?->nama." (sudah di baris {$other->layer})";
            }
        }
        if ($taken) {
            throw ValidationException::withMessages(['sub_group_ids' => 'SubGroup hanya boleh di satu baris plafon: '.implode(', ', $taken).'.']);
        }

        return $data;
    }

    private static function plafonRow(Model $p): array
    {
        return [
            'id' => $p->id,
            'layer' => $p->layer,
            'golongan_ids' => $p->golongan_ids,
            'sub_group_ids' => $p->sub_group_ids,
            'jabatan_ho' => $p->jabatan_ho,
            'jabatan_site' => $p->jabatan_site,
        ];
    }

    /** Saves the Tunjangan Jabatan of every SubGroup at once ({sub_group_id: nominal}). */
    public function updateTunjangan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nominal' => ['present', 'array'],
            'nominal.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $ids = SubGroup::pluck('id')->all();
        foreach ($data['nominal'] as $id => $nominal) {
            if (in_array((int) $id, $ids, true)) {
                TunjanganJabatan::updateOrCreate(['sub_group_id' => (int) $id], ['nominal' => (int) ($nominal ?? 0)]);
            }
        }
        Inertia::flash('success', 'Tunjangan Jabatan disimpan.');

        return back();
    }
}
