<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\CutiAturan;
use App\Models\CutiBesar;
use App\Models\CutiPengambilan;
use App\Models\Karyawan;
use App\Models\Trip;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Roll;
use App\Support\Cuti\Cuti;
use App\Support\Cuti\CutiExcel;
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

        return response()->json([
            'karyawan' => ['id' => $karyawan->id, 'nrp' => $karyawan->nrp, 'name' => $karyawan->name, 'jabatan' => $karyawan->jabatan?->name],
            ...Cuti::summary($karyawan, data: $data),
            'pengambilan' => self::items($karyawan, $data, true),
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

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $book = CutiExcel::build($this->rows([...$filters, 'tab' => 'tahunan']), CarbonImmutable::today());

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'sisa-cuti-tahunan-'.CarbonImmutable::today()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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

    private function rows(array $f): array
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

        $rows = $karyawans->map(function (Karyawan $k) use ($data, $today) {
            $s = Cuti::summary($k, $today, $data);

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
                'tahunan' => array_diff_key($s['tahunan'], ['tahun' => 1]),
                'besar' => $s['besar'],
                'pengambilan' => self::items($k, $data),
            ];
        });

        if ($f['tab'] === 'besar' && $f['status']) {
            $rows = $rows->filter(fn ($r) => collect($r['besar']['periode'])->contains('status', $f['status']));
        }

        return $rows->values()->all();
    }

    /** Every leave of the employee (imported, typed, and from Trip & Leave), oldest first. */
    public static function items(Karyawan $k, array $data, bool $withIds = false): array
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
