<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\KaryawanGaji;
use App\Models\SkalaUpah;
use App\Support\Access\AccessScope;
use App\Support\Payroll\Gaji;
use App\Support\Payroll\Ptkp;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comben → Data Gaji: each employee's golongan and step in the Struktur Skala Upah (with
 * its history), the Gaji Pokok it gives at their location, the Tunjangan Jabatan of their
 * SubGroup, and their status PTKP — the fixed part of payroll.
 */
class GajiController extends Controller
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $lokasi = $request->integer('lokasi') ?: null;
        if ($lokasi && ! $locations->contains('id', $lokasi)) {
            $lokasi = null;
        }
        $karyawans = Karyawan::where('status', Karyawan::STATUS_AKTIF)
            ->when($lokasi, fn ($q) => $q->where('work_location_id', $lokasi))
            ->with(['workLocation:id,name,order', 'subGroup:id,nama,layer', 'jabatan:id,name', 'profile.anak', 'gajis.skalaUpah.golongan'])
            ->orderBy('nrp')->get();

        $rows = $karyawans->map(function (Karyawan $k) use ($today) {
            $posisi = Gaji::posisi($k, $today);
            $pokok = $posisi ? Gaji::nominal($posisi->skala_upah_id, $k->work_location_id, $today->year) : null;
            $tunjangan = Gaji::tunjanganJabatan($k);
            $ptkp = Ptkp::status($k);

            return [
                'id' => $k->id, 'nrp' => $k->nrp, 'nama' => $k->name,
                'lokasi' => $k->workLocation?->name, 'lokasi_order' => $k->workLocation?->order ?? 0,
                'sub_group' => $k->subGroup?->nama, 'jabatan' => $k->jabatan?->name,
                'klasifikasi' => $k->profile?->klasifikasi, 'jenis_kelamin' => $k->profile?->jenis_kelamin,
                'status_nikah' => $k->profile?->status_perkawinan, 'anak' => $k->profile?->anak->count() ?? 0,
                'golongan' => $posisi?->skalaUpah?->golongan?->kode ?? $k->profile?->golongan,
                'step' => $posisi?->skalaUpah?->step,
                'skala_upah_id' => $posisi?->skala_upah_id,
                'berlaku_mulai' => $posisi?->berlaku_mulai?->toDateString(),
                'gaji_pokok' => $pokok, 'tunjangan_jabatan' => $tunjangan,
                'upah_tetap' => $pokok === null ? null : $pokok + $tunjangan,
                'ptkp' => $ptkp, 'ter' => Ptkp::kategoriTer($ptkp),
                'masalah' => ! $posisi ? 'Belum ada golongan/step' : ($pokok === null ? 'Nilai SSU kosong untuk lokasi/tahun ini' : null),
                'riwayat' => $k->gajis->map(fn (KaryawanGaji $g) => [
                    'id' => $g->id, 'berlaku_mulai' => $g->berlaku_mulai->toDateString(), 'golongan' => $g->skalaUpah?->golongan?->kode,
                    'step' => $g->skalaUpah?->step, 'alasan' => KaryawanGaji::ALASAN[$g->alasan] ?? $g->alasan, 'catatan' => $g->catatan,
                    'nominal' => Gaji::nominal($g->skala_upah_id, $k->work_location_id, $g->berlaku_mulai->year),
                ])->values(),
            ];
        })->sortBy([['lokasi_order', 'asc'], ['nama', 'asc']])->values();

        return Inertia::render('Comben/DataGaji', [
            'rows' => $rows,
            'locations' => $locations,
            'lokasi' => $lokasi,
            'summary' => [
                'karyawan' => $rows->count(),
                'total_pokok' => $rows->sum('gaji_pokok'),
                'total_tunjangan' => $rows->sum('tunjangan_jabatan'),
                'total_upah_tetap' => $rows->sum('upah_tetap'),
                'masalah' => $rows->whereNotNull('masalah')->count(),
            ],
            'skala' => SkalaUpah::with('golongan:id,kode,grup,urutan')->get()
                ->sortBy([fn ($a, $b) => $a->golongan->urutan <=> $b->golongan->urutan, fn ($a, $b) => $a->urutan <=> $b->urutan])
                ->values()->map(fn (SkalaUpah $s) => ['id' => $s->id, 'grup' => $s->golongan->grup, 'golongan' => $s->golongan->kode, 'step' => $s->step]),
            'alasan' => KaryawanGaji::ALASAN,
            'can_edit' => $request->user()->canEdit('data-gaji'),
        ]);
    }

    /** A new place in the scale from a date (a raise, a promotion, a correction). */
    public function store(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate([
            'skala_upah_id' => ['required', 'integer', 'exists:skala_upahs,id'],
            'berlaku_mulai' => ['required', 'date'],
            'alasan' => ['required', Rule::in(array_keys(KaryawanGaji::ALASAN))],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], [], ['skala_upah_id' => 'Golongan / step', 'berlaku_mulai' => 'Berlaku mulai']);
        $g = $karyawan->gajis()->create($data + ['created_by' => $request->user()->id]);
        // The profile's golongan follows (it decides the plafon of Comben).
        $karyawan->profile?->update(['golongan' => $g->skalaUpah->golongan->kode]);
        Inertia::flash('success', "Golongan {$g->skalaUpah->golongan->kode}".($g->skalaUpah->step ? " {$g->skalaUpah->step}" : '')." untuk {$karyawan->name} disimpan.");

        return back();
    }

    public function destroy(KaryawanGaji $gaji): RedirectResponse
    {
        abort_unless(Karyawan::whereKey($gaji->karyawan_id)->exists(), 404); // scoped
        $gaji->delete();
        Inertia::flash('success', 'Riwayat gaji dihapus.');

        return back();
    }
}
