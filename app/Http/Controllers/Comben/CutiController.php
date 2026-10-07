<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\CutiAturan;
use App\Models\CutiBesar;
use App\Models\CutiPengambilan;
use App\Models\Karyawan;
use App\Models\Trip;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Roll;
use App\Support\Cuti\Cuti;
use App\Support\Cuti\CutiExcel;
use App\Support\Excel\TableExcel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comben → Cuti: the annual leave balance of every employee (as the old "SISA CUTI
 * TAHUNAN" monitoring: each leave as Hari | Tgl), the Cuti Besar entitlements and
 * choices, leave typed by HR, and the export in the same layout.
 */
class CutiController extends Controller
{
    private const BESAR_STATUS = [
        'belum_dipilih' => 'Belum dipilih',
        'menunggu' => 'Menunggu HR',
        'diambil' => 'Diambil',
        'diuangkan' => 'Diuangkan',
        'otomatis_diuangkan' => 'Lewat batas → diuangkan',
    ];

    private const SUMBER = ['import' => 'Import', 'manual' => 'Dicatat HR', 'trip' => 'Trip & Leave'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Comben/Cuti', [
            'tab' => $filters['tab'],
            'filters' => array_diff_key($filters, ['tab' => 1]),
            'rows' => $this->rows($filters),
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'aturan' => CutiAturan::current()->only(['jatah_tahunan', 'masa_aktif_tahun', 'besar_interval_tahun', 'besar_hari', 'besar_gapok_diambil', 'besar_gapok_diuangkan', 'besar_batas_bulan']),
            'menunggu_besar' => CutiBesar::where('status', 'diajukan')->count(),
            'can_edit' => $request->user()->canEdit('cuti'),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /** One employee: the balance per year and every leave taken. */
    public function show(Karyawan $karyawan): JsonResponse
    {
        $karyawan->load(['profile', 'jabatan:id,name', 'workLocation:id,name', 'subLocation:id,name']);
        $data = Cuti::load([$karyawan->id]);
        $summary = Cuti::summary($karyawan, data: $data);

        return response()->json([
            'karyawan' => ['id' => $karyawan->id, 'nrp' => $karyawan->nrp, 'name' => $karyawan->name, 'jabatan' => $karyawan->jabatan?->name],
            'tahunan' => array_diff_key($summary['tahunan'], ['alokasi' => 1]),
            'besar' => $summary['besar'],
            'pengambilan' => self::items($karyawan, $data, true, $summary['tahunan']['alokasi'] ?? []),
            'lapangan' => ($l = Cuti::lapangan($karyawan)) ? ['cuti' => $l->hari_cuti, 'berangkat' => $l->hari_berangkat, 'kembali' => $l->hari_kembali, 'total' => $l->total()] : null,
        ]);
    }

    /** Leave taken outside Trip & Leave (before the app, or a correction). */
    public function storePengambilan(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(['tahunan'])],
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'hari' => ['required', 'integer', 'min:1', 'max:366'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], [], ['tanggal_awal' => 'Tanggal awal', 'tanggal_akhir' => 'Tanggal akhir', 'hari' => 'Jumlah hari']);
        $karyawan->cutiPengambilans()->create([
            ...$data,
            'tahun' => (int) substr($data['tanggal_awal'], 0, 4),
            'sumber' => 'manual',
            'created_by' => $request->user()->id,
        ]);
        Inertia::flash('success', "Cuti {$data['hari']} hari {$karyawan->name} dicatat.");

        return back();
    }

    public function destroyPengambilan(CutiPengambilan $pengambilan): RedirectResponse
    {
        $pengambilan->delete();
        Inertia::flash('success', "Catatan cuti {$pengambilan->hari} hari dihapus; saldonya kembali.");

        return back();
    }

    /** HR records the employee's choice for a Cuti Besar entitlement (or clears it). */
    public function decideBesar(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate([
            'periode_ke' => ['required', 'integer', 'min:1', 'max:20'],
            'keputusan' => ['nullable', Rule::in(array_keys(CutiBesar::KEPUTUSAN))],
        ]);
        $this->assertReached($karyawan, $data['periode_ke']);
        $current = CutiBesar::where('karyawan_id', $karyawan->id)->where('periode_ke', $data['periode_ke'])->first();
        if (! $data['keputusan']) {
            if ($current && Trip::where('karyawan_id', $karyawan->id)->where('cuti_besar_periode', $data['periode_ke'])->where('status', '!=', 'ditolak')->exists()) {
                throw ValidationException::withMessages(['keputusan' => 'Cuti Besar ini sudah dipakai di Trip & Leave; hapus cutinya dulu.']);
            }
            $current?->delete();
            Inertia::flash('success', "Pilihan Cuti Besar {$karyawan->name} dikosongkan.");

            return back();
        }
        CutiBesar::updateOrCreate(
            ['karyawan_id' => $karyawan->id, 'periode_ke' => $data['periode_ke']],
            ['keputusan' => $data['keputusan'], 'status' => 'disetujui', 'sumber' => $current?->sumber ?? 'hr', 'verified_by' => $request->user()->id, 'verified_at' => now()],
        );
        Inertia::flash('success', "Cuti Besar {$karyawan->name}: ".CutiBesar::KEPUTUSAN[$data['keputusan']].'.');

        return back();
    }

    /** A choice sent from the portal: confirmed, or rejected (the entitlement is open again). */
    public function verifyBesar(Request $request, CutiBesar $cutiBesar): RedirectResponse
    {
        $data = $request->validate(['keputusan' => ['required', Rule::in(['setujui', 'tolak'])], 'catatan' => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak']], ['catatan.required_if' => 'Tulis alasan penolakan.']);
        if ($cutiBesar->status !== 'diajukan') {
            throw ValidationException::withMessages(['keputusan' => 'Pilihan ini sudah diverifikasi.']);
        }
        $name = $cutiBesar->karyawan->name;
        if ($data['keputusan'] === 'tolak') {
            $cutiBesar->delete();
            Inertia::flash('success', "Pilihan Cuti Besar {$name} ditolak; ia bisa memilih lagi.");
        } else {
            $cutiBesar->update(['status' => 'disetujui', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'catatan' => $data['catatan'] ?? null]);
            Inertia::flash('success', "Cuti Besar {$name} disetujui: ".CutiBesar::KEPUTUSAN[$cutiBesar->keputusan].'.');
        }

        return back();
    }

    /**
     * Excel: the "SISA CUTI TAHUNAN" sheet in the old layout, the Cuti Besar entitlements
     * (one row per period; the status filter of the Cuti Besar tab applies) and every leave
     * with the quota it was taken from.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $today = CarbonImmutable::today();
        $rows = $this->rows([...$filters, 'tab' => 'tahunan'], true);
        $book = CutiExcel::build($rows, $today);
        $aturan = CutiAturan::current();
        $scope = [
            $filters['lokasi'] ? 'Lokasi '.WorkLocation::whereKey($filters['lokasi'])->value('name') : null,
            $filters['q'] !== '' ? 'Cari "'.$filters['q'].'"' : null,
            count($rows).' karyawan aktif',
        ];

        $besar = [];
        foreach ($rows as $r) {
            $identity = [$r['nrp'], $r['name'], $r['departemen'], $r['work_location'], $r['sub_location'], $r['masuk'], $r['masa_kerja']];
            $periode = collect($r['besar']['periode'])->when($filters['status'], fn ($c, $st) => $c->where('status', $st));
            foreach ($periode as $p) {
                $besar[] = [
                    count($besar) + 1, ...$identity,
                    $p['periode_ke'], $p['masa_kerja'], $p['tanggal_hak'], $p['batas'],
                    self::BESAR_STATUS[$p['status']] ?? $p['status'],
                    $p['status'] === 'diambil' ? $p['hari'] : null,
                    $p['status'] === 'diambil' ? $p['terpakai'] : null,
                    $p['menunggu'] ?: null,
                    $p['status'] === 'diambil' ? $p['sisa'] : null,
                    $p['gapok'] ? "{$p['gapok']}x gapok" : null,
                    $r['besar']['berikutnya'],
                ];
            }
            // Not reached yet: one row with when it will be (left out when filtering by status).
            if (! $r['besar']['periode'] && ! $filters['status']) {
                $besar[] = [count($besar) + 1, ...$identity, null, null, null, null, $r['masuk'] ? 'Belum tercapai' : 'Tgl masuk kosong', null, null, null, null, null, $r['besar']['berikutnya']];
            }
        }
        TableExcel::addSheet($book, [
            'title' => 'CUTI BESAR',
            'info' => TableExcel::info([
                "Tiap {$aturan->besar_interval_tahun} thn: diambil {$aturan->besar_hari} hari + {$aturan->besar_gapok_diambil}x gapok, atau diuangkan {$aturan->besar_gapok_diuangkan}x gapok; batas pilih {$aturan->besar_batas_bulan} bulan",
                $filters['status'] ? 'Status '.(self::BESAR_STATUS[$filters['status']] ?? $filters['status']) : null,
                ...$scope,
            ]),
            'freeze' => 3,
            'columns' => [
                ['No', 5, 'int'], ['NRP', 12], ['Nama', 28], ['Departemen', 22], ['Lokasi', 16], ['Sub Lokasi', 16], ['Tgl Masuk', 12, 'date'], ['Masa Kerja', 16],
                ['Periode Ke', 8, 'int'], ['Masa Kerja (thn)', 9, 'int'], ['Tanggal Hak', 12, 'date'], ['Batas Pilih', 12, 'date'], ['Status', 22],
                ['Hak Hari', 8, 'int'], ['Terpakai', 8, 'int'], ['Menunggu', 8, 'int'], ['Sisa Hari', 8, 'int'], ['Gapok', 10], ['Hak Berikutnya', 13, 'date'],
            ],
            'rows' => $besar,
        ]);

        $rincian = [];
        foreach ($rows as $r) {
            foreach ($r['pengambilan'] as $item) {
                $rincian[] = [
                    count($rincian) + 1, $r['nrp'], $r['name'], $r['departemen'],
                    Cuti::KATEGORI[$item['jenis']] ?? ucfirst($item['jenis']),
                    self::SUMBER[$item['sumber']] ?? $item['sumber'],
                    $item['tahun'], $item['tanggal_awal'], $item['tanggal_akhir'], $item['tanggal_awal'] ? null : $item['keterangan'],
                    $item['hari'], $item['potong'] ?: null, ($item['potong_besar'] ?? 0) ?: null,
                    self::potongDari($item),
                    ucfirst($item['status']), $item['catatan'],
                ];
            }
        }
        TableExcel::addSheet($book, [
            'title' => 'RINCIAN PENGAMBILAN',
            'info' => TableExcel::info(['Sisa per '.$today->format('d.m.Y'), ...$scope]),
            'freeze' => 3,
            'columns' => [
                ['No', 5, 'int'], ['NRP', 12], ['Nama', 28], ['Departemen', 22], ['Jenis Cuti', 20], ['Sumber', 12], ['Tahun', 7, 'int'],
                ['Tgl Awal', 12, 'date'], ['Tgl Akhir', 12, 'date'], ['Tanggal (teks lama)', 22], ['Hari', 6, 'int', 'sum' => true],
                ['Potong Tahunan', 9, 'int', 'sum' => true], ['Potong Besar', 9, 'int', 'sum' => true], ['Dipotong dari Jatah', 34], ['Status', 11], ['Catatan', 30],
            ],
            'rows' => $rincian,
        ]);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'cuti-tahunan-besar-'.$today->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** "Cuti 2024 · 2 hr, Hutang → cuti 2026 · 1 hr", as in the detail popup. */
    private static function potongDari(array $item): ?string
    {
        if ($item['status'] === 'diajukan') {
            return 'menunggu persetujuan';
        }
        if (! $item['potong']) {
            return 'tidak memotong';
        }

        return implode(', ', array_map(fn (array $p) => ($p['hutang'] ? ($p['jatah'] ? "Hutang → cuti {$p['jatah']}" : 'Hutang') : "Cuti {$p['jatah']}")." · {$p['hari']} hr", $item['potong_dari'])) ?: null;
    }

    /** For the cuti form in Trip & Leave: balance, field-leave allowance, Cuti Besar. */
    public function info(Request $request, Karyawan $karyawan): JsonResponse
    {
        return response()->json(Cuti::info($karyawan, $request->integer('kecuali') ?: null));
    }

    // -------------------------------------------------------------------------------------

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(['tahunan', 'besar'])],
            'lokasi' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        return ['tab' => $f['tab'] ?? 'tahunan', 'lokasi' => $f['lokasi'] ?? null, 'sub' => $f['sub'] ?? '', 'q' => $f['q'] ?? '', 'status' => $f['status'] ?? ''];
    }

    /** Employees with their balance; $withItems adds every leave (the export lists them as Hari | Tgl). */
    private function rows(array $f, bool $withItems = false): array
    {
        $sub = Roll::subLocationFilter($f['sub'] ?: null);
        $q = trim($f['q']);
        $karyawans = Karyawan::with(['profile', 'jabatan:id,name', 'departemen:id,name', 'section.departemen', 'workLocation:id,name', 'subLocation:id,name'])
            ->where('status', Karyawan::STATUS_AKTIF)
            ->when($f['lokasi'], fn ($w, $l) => $w->where('work_location_id', $l))
            ->when($sub !== null, fn ($w) => $sub === Roll::NO_SUB_LOCATION ? $w->whereNull('sub_location_id') : $w->where('sub_location_id', $sub))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('nrp', 'like', "%{$q}%")))
            ->orderBy('name')
            ->get();
        $data = Cuti::load($karyawans->pluck('id'));
        $today = CarbonImmutable::today();

        $rows = $karyawans->map(function (Karyawan $k) use ($data, $today, $withItems) {
            $s = Cuti::summary($k, $today, $data);
            $items = self::items($k, $data, false, $s['tahunan']['alokasi'] ?? []);

            return [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'jabatan' => $k->jabatan?->name,
                'departemen' => $k->placementDepartemen()?->name,
                'work_location' => $k->workLocation?->name,
                'sub_location' => $k->subLocation?->name,
                'masuk' => $k->profile?->tanggal_masuk?->toDateString(),
                'masa_kerja' => $k->profile?->masa_kerja,
                'tahunan' => array_diff_key($s['tahunan'], ['tahun' => 1, 'alokasi' => 1]),
                'besar' => $s['besar'],
                'jumlah_pengambilan' => count($items),
                'pengambilan' => $withItems ? $items : [],
            ];
        });

        if ($f['tab'] === 'besar' && $f['status']) {
            $rows = $rows->filter(fn ($r) => collect($r['besar']['periode'])->contains('status', $f['status']));
        }

        return $rows->values()->all();
    }

    /**
     * Every leave of the employee (imported, typed, and from Trip & Leave), oldest first,
     * with the quota each one was taken from ($alokasi from the ledger).
     */
    public static function items(Karyawan $k, array $data, bool $withIds = false, array $alokasi = []): array
    {
        $items = collect();
        foreach ($data['pengambilan'][$k->id] ?? [] as $p) {
            $items->push([
                'id' => $withIds ? $p->id : null,
                'sumber' => $p->sumber,
                'jenis' => $p->jenis,
                'tahun' => $p->tahun,
                'tanggal_awal' => $p->tanggal_awal?->toDateString(),
                'tanggal_akhir' => $p->tanggal_akhir?->toDateString(),
                'keterangan' => $p->keterangan_tanggal,
                'hari' => $p->hari,
                'potong' => $p->hari,
                'status' => 'disetujui',
                'catatan' => $p->catatan,
                'potong_dari' => $alokasi["p{$p->id}"] ?? [],
            ]);
        }
        foreach ($data['trips'][$k->id] ?? [] as $t) {
            $items->push([
                'id' => null,
                'trip_id' => $t->id,
                'sumber' => 'trip',
                'jenis' => $t->kategori_cuti ?? 'lainnya',
                'tahun' => $t->tanggal_awal->year,
                'tanggal_awal' => $t->tanggal_awal->toDateString(),
                'tanggal_akhir' => $t->tanggal_akhir->toDateString(),
                'keterangan' => null,
                'hari' => $t->hari_cuti ?? $t->lamaHari(),
                'potong' => $t->potong_tahunan,
                'potong_besar' => $t->potong_besar,
                'status' => $t->status,
                'catatan' => $t->keterangan,
                'potong_dari' => $alokasi["t{$t->id}"] ?? [],
            ]);
        }

        return $items->sortBy(fn ($i) => sprintf('%04d-%s', $i['tahun'], $i['tanggal_awal'] ?? '00'))->values()->all();
    }

    private function assertReached(Karyawan $k, int $periode): void
    {
        $reached = collect(Cuti::summary($k)['besar']['periode'])->pluck('periode_ke');
        if (! $reached->contains($periode)) {
            throw ValidationException::withMessages(['periode_ke' => 'Hak Cuti Besar periode ini belum tercapai.']);
        }
    }
}
