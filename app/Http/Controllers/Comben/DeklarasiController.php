<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\Deklarasi;
use App\Models\DeklarasiItem;
use App\Models\Karyawan;
use App\Models\Lampiran;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\DeklarasiCheck;
use App\Support\Comben\Entitlement;
use App\Support\Comben\Usage;
use App\Support\Lampiran\Attachments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deklarasi Pengobatan / Kacamata: the claim form (as on paper, without
 * Persalinan), checked against the plafon and booked to a month closing on the 8th.
 */
class DeklarasiController extends Controller
{
    private const RELATIONS = ['jabatan', 'subGroup', 'departemen', 'section.departemen', 'workLocation', 'profile.anak'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'jenis' => ['nullable', Rule::in(['pengobatan', 'kacamata'])],
            'status' => ['nullable', Rule::in(Deklarasi::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $jenis = $filters['jenis'] ?? null;

        $deklarasis = Deklarasi::with(['karyawan.jabatan', 'karyawan.subGroup', 'items.anak', 'lampirans'])
            ->when($filters['bulan'] ?? null, fn ($q, $b) => $q->where('bulan_buku', $b))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($jenis, fn ($q) => $q->whereHas('items', fn ($i) => $i->whereIn('jenis', $jenis === 'kacamata' ? DeklarasiItem::KACAMATA : DeklarasiItem::PENGOBATAN)))
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => $q->where(fn ($w) => $w->where('nomor', 'like', "%{$s}%")
                ->orWhereHas('karyawan', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('nrp', 'like', "%{$s}%"))))
            // Waiting for HR first.
            ->orderByRaw("case when status = 'diajukan' then 0 else 1 end")
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Deklarasi $d) => self::row($d));

        $today = CarbonImmutable::today();
        $current = BukuBulan::of($today);

        return Inertia::render('Comben/Deklarasi', [
            'deklarasis' => $deklarasis,
            'filters' => ['bulan' => $filters['bulan'] ?? '', 'jenis' => $jenis ?? '', 'status' => $filters['status'] ?? '', 'q' => $filters['q'] ?? ''],
            'menunggu' => Deklarasi::where('status', 'diajukan')->count(),
            'can_edit' => $request->user()->canEdit('deklarasi'),
            'lampiran_limits' => Attachments::limits(),
            'bulan_buku' => [
                'current' => $current,
                'label' => BukuBulan::label($current),
                'span' => BukuBulan::span($current),
                'options' => collect(range(-12, 2))->map(function (int $o) use ($current) {
                    $value = CarbonImmutable::parse("{$current}-01")->addMonths($o)->format('Y-m');

                    return ['value' => $value, 'label' => BukuBulan::label($value)];
                })->reverse()->values(),
            ],
            'people' => Karyawan::with(['jabatan:id,name'])->where('status', '!=', Karyawan::STATUS_KELUAR)->orderBy('nrp')->get()
                ->map(fn (Karyawan $k) => ['id' => $k->id, 'nrp' => $k->nrp, 'name' => $k->name, 'jabatan' => $k->jabatan?->name]),
            // Places typed before (the "Ugang Sayu, 2 Okt 2026" line), as suggestions.
            'tempat_options' => Deklarasi::whereNotNull('tempat')->distinct()->orderBy('tempat')->pluck('tempat'),
        ]);
    }

    /** For the form: who the employee is, their family and what is left of each plafon on that date. */
    public function info(Request $request, Karyawan $karyawan): JsonResponse
    {
        $data = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'kecuali' => ['nullable', 'integer'],
        ]);
        $karyawan->load(self::RELATIONS);
        $tanggal = CarbonImmutable::parse($data['tanggal'] ?? CarbonImmutable::today()->toDateString());
        $bulan = BukuBulan::of($tanggal);

        return response()->json([
            'karyawan' => self::identity($karyawan),
            'bulan_buku' => ['value' => $bulan, 'label' => BukuBulan::label($bulan), 'span' => BukuBulan::span($bulan)],
            ...Usage::summary($karyawan, BukuBulan::tahun($bulan), $data['kecuali'] ?? null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$karyawan, $data, $items] = self::validateClaim($request);
        $deklarasi = DB::transaction(function () use ($data, $items, $request) {
            // Entered by HR from the signed form: approved as it is.
            $deklarasi = Deklarasi::create([
                ...$data,
                'nomor' => self::nextNomor($data['bulan_buku']),
                'created_by' => $request->user()->id,
                'status' => 'disetujui',
                'sumber' => 'hr',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);
            $deklarasi->items()->createMany($items);
            Attachments::sync($request, $deklarasi, 'deklarasi');

            return $deklarasi;
        });
        Inertia::flash('success', "Deklarasi {$deklarasi->nomor} {$karyawan->name} disimpan (bulan buku ".BukuBulan::label($deklarasi->bulan_buku).').');

        return back();
    }

    public function update(Request $request, Deklarasi $deklarasi): RedirectResponse
    {
        [$karyawan, $data, $items] = self::validateClaim($request, $deklarasi);
        DB::transaction(function () use ($deklarasi, $data, $items, $request) {
            $deklarasi->update($data);
            $deklarasi->items()->delete();
            $deklarasi->items()->createMany($items);
            Attachments::sync($request, $deklarasi, 'deklarasi');
        });
        Inertia::flash('success', "Deklarasi {$deklarasi->nomor} {$karyawan->name} diperbarui.");

        return back();
    }

    public function destroy(Deklarasi $deklarasi): RedirectResponse
    {
        $deklarasi->delete();
        Inertia::flash('success', "Deklarasi {$deklarasi->nomor} dihapus; plafonnya kembali.");

        return back();
    }

    /** Approves (it then counts as "terpakai") or rejects (with a note; it no longer holds the plafon) a deklarasi from the portal. */
    public function verify(Request $request, Deklarasi $deklarasi): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak'],
        ], ['catatan.required_if' => 'Tulis alasan penolakan.']);
        if ($deklarasi->status !== 'diajukan') {
            throw ValidationException::withMessages(['keputusan' => "Deklarasi {$deklarasi->nomor} sudah diverifikasi."]);
        }
        $approve = $data['keputusan'] === 'setujui';
        if ($approve) {
            $karyawan = Karyawan::with(self::RELATIONS)->findOrFail($deklarasi->karyawan_id);
            $items = $deklarasi->items->map(fn (DeklarasiItem $i) => $i->only(['jenis', 'penerima', 'karyawan_anak_id', 'nominal']))->all();
            if ($errors = DeklarasiCheck::errors($karyawan, $items, $deklarasi->tahun_plafon, $deklarasi->id)) {
                throw ValidationException::withMessages(['keputusan' => $errors]);
            }
        }
        $deklarasi->update([
            'status' => $approve ? 'disetujui' : 'ditolak',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'catatan_verifikasi' => $data['catatan'] ?? null,
        ]);
        Inertia::flash('success', "Deklarasi {$deklarasi->nomor} ".($approve ? 'disetujui.' : 'ditolak.'));

        return back();
    }

    /**
     * The form checked against the plafon (also used by the employee portal).
     *
     * @return array{0: Karyawan, 1: array, 2: list<array>}
     */
    public static function validateClaim(Request $request, ?Deklarasi $current = null): array
    {
        $data = $request->validate([
            'karyawan_id' => ['required', 'integer', Rule::exists('karyawans', 'id')],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'tempat' => ['nullable', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.jenis' => ['required', Rule::in(array_keys(DeklarasiItem::JENIS))],
            'items.*.penerima' => ['required', Rule::in(array_keys(DeklarasiItem::PENERIMA))],
            'items.*.karyawan_anak_id' => ['nullable', 'integer'],
            'items.*.nominal' => ['required', 'integer', 'min:1', 'max:999999999'],
            ...Attachments::rules(),
        ], [
            'items.required' => 'Isi minimal satu nominal di tabel deklarasi.',
            ...Attachments::messages(),
        ], [
            'karyawan_id' => 'NRP',
            'tanggal' => 'Tanggal',
            'lampiran.*' => 'Lampiran',
            'items.*.nominal' => 'Nominal',
        ]);

        // Each photo, and each page of a PDF, is one nota.
        $nota = Attachments::require($request, $current, 'Lampirkan foto / PDF nota (minimal satu).');
        $karyawan = Karyawan::with(self::RELATIONS)->findOrFail($data['karyawan_id']);
        if (! $current && $karyawan->status === Karyawan::STATUS_KELUAR) {
            throw ValidationException::withMessages(['karyawan_id' => "{$karyawan->name} sudah keluar."]);
        }
        $items = array_map(fn (array $i) => [
            'jenis' => $i['jenis'],
            'penerima' => $i['penerima'],
            'karyawan_anak_id' => $i['penerima'] === 'anak' ? ($i['karyawan_anak_id'] ?? null) : null,
            'nominal' => (int) $i['nominal'],
        ], $data['items']);

        $bulan = BukuBulan::of(CarbonImmutable::parse($data['tanggal']));
        $tahun = BukuBulan::tahun($bulan);
        if ($errors = DeklarasiCheck::errors($karyawan, $items, $tahun, $current?->id)) {
            throw ValidationException::withMessages(['items' => $errors]);
        }

        return [$karyawan, [
            'karyawan_id' => $karyawan->id,
            'tanggal' => $data['tanggal'],
            'tempat' => filled($data['tempat'] ?? null) ? trim($data['tempat']) : null,
            'jumlah_kwitansi' => $nota,
            'keterangan' => $data['keterangan'] ?? null,
            'bulan_buku' => $bulan,
            'tahun_plafon' => $tahun,
        ], $items];
    }

    /** DKL/2026/10/0001 — numbered within the book month. */
    public static function nextNomor(string $bulan): string
    {
        $prefix = 'DKL/'.str_replace('-', '/', $bulan).'/';
        $last = Deklarasi::where('nomor', 'like', "{$prefix}%")->orderByDesc('nomor')->value('nomor');

        return $prefix.sprintf('%04d', $last ? ((int) substr($last, -4)) + 1 : 1);
    }

    public static function identity(Karyawan $k): array
    {
        return [
            'id' => $k->id,
            'nrp' => $k->nrp,
            'name' => $k->name,
            'jabatan' => $k->jabatan?->name,
            'sub_group' => $k->subGroup?->nama,
            'departemen' => $k->placementDepartemen()?->name,
            'work_location' => $k->workLocation?->name,
            'golongan' => $k->profile?->golongan,
            'status_ptkp' => Entitlement::statusPtkp($k),
        ];
    }

    public static function row(Deklarasi $d): array
    {
        $sum = fn (array $jenis) => $d->items->whereIn('jenis', $jenis)->sum('nominal');

        return [
            'id' => $d->id,
            'nomor' => $d->nomor,
            'tanggal' => $d->tanggal->toDateString(),
            'tempat' => $d->tempat,
            'bulan_buku' => $d->bulan_buku,
            'bulan_label' => BukuBulan::label($d->bulan_buku),
            'jumlah_kwitansi' => $d->jumlah_kwitansi,
            'keterangan' => $d->keterangan,
            'karyawan_id' => $d->karyawan_id,
            'nrp' => $d->karyawan->nrp,
            'name' => $d->karyawan->name,
            'jabatan' => $d->karyawan->jabatan?->name,
            'pengobatan' => $sum(DeklarasiItem::PENGOBATAN),
            'kacamata' => $sum(DeklarasiItem::KACAMATA),
            'total' => $d->items->sum('nominal'),
            'status' => $d->status,
            'sumber' => $d->sumber,
            'catatan_verifikasi' => $d->catatan_verifikasi,
            'lampiran' => $d->lampirans->map(fn (Lampiran $l) => $l->toView())->values(),
            'items' => $d->items->map(fn (DeklarasiItem $i) => [
                'jenis' => $i->jenis,
                'penerima' => $i->penerima,
                'karyawan_anak_id' => $i->karyawan_anak_id,
                'anak' => $i->anak?->nama,
                'nominal' => $i->nominal,
            ])->values(),
        ];
    }
}
