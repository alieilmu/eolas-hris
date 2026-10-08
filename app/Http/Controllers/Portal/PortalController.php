<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Attendance\TripController;
use App\Http\Controllers\Comben\DeklarasiController;
use App\Http\Controllers\Controller;
use App\Models\CutiAturan;
use App\Models\CutiBesar;
use App\Models\Deklarasi;
use App\Models\Karyawan;
use App\Models\PengajuanUpdateData;
use App\Models\Trip;
use App\Support\Attendance\Bandara;
use App\Support\Attendance\TripWriter;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\Usage;
use App\Support\Cuti\Cuti;
use App\Support\Lampiran\Attachments;
use App\Support\Notifikasi\Pengajuan as NotifikasiPengajuan;
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
 * Portal Karyawan: an employee's own plafon, and their pengajuan — deklarasi
 * pengobatan / kacamata, cuti and dinas — which wait for HR to approve them.
 */
class PortalController extends Controller
{
    private const RELATIONS = ['jabatan', 'subGroup', 'departemen', 'section.departemen', 'workLocation', 'profile.anak'];

    private function me(Request $request): Karyawan
    {
        return Karyawan::with(self::RELATIONS)->findOrFail($request->user()->karyawan_id);
    }

    public function home(Request $request): Response
    {
        $k = $this->me($request);
        $today = CarbonImmutable::today();
        $bulan = BukuBulan::of($today);

        return Inertia::render('Portal/Home', [
            'karyawan' => DeklarasiController::identity($k),
            'plafon' => Usage::summary($k, BukuBulan::tahun($bulan)),
            'bulan_buku' => ['value' => $bulan, 'label' => BukuBulan::label($bulan), 'span' => BukuBulan::span($bulan)],
            'deklarasis' => Deklarasi::with(['karyawan.jabatan', 'items.anak', 'lampirans'])->where('karyawan_id', $k->id)->latest('tanggal')->latest('id')->limit(20)->get()
                ->map(fn (Deklarasi $d) => DeklarasiController::row($d)),
            'trips' => Trip::with(['karyawan.jabatan', 'karyawan.workLocation', 'lampirans'])->where('karyawan_id', $k->id)->latest('tanggal_awal')->limit(20)->get()
                ->map(fn (Trip $t) => TripController::tripData($t, $today)),
            'cities' => Trip::query()->select('kota_asal as kota')->union(Trip::query()->select('kota_tujuan as kota'))->pluck('kota')->unique()->sort()->values(),
            'tempat_options' => Deklarasi::whereNotNull('tempat')->distinct()->orderBy('tempat')->pluck('tempat'),
            'bandara' => Bandara::options(),
            'lampiran_limits' => Attachments::limits(),
            'pengajuan_data' => PengajuanUpdateData::where('karyawan_id', $k->id)->where('status', 'diajukan')->exists(),
            'cuti' => Cuti::info($k),
            'cuti_aturan' => CutiAturan::current()->only(['besar_hari', 'besar_gapok_diambil', 'besar_gapok_diuangkan']),
        ]);
    }

    /** Own leave balance, for the cuti form. */
    public function cutiInfo(Request $request): JsonResponse
    {
        return response()->json(Cuti::info($this->me($request), $request->integer('kecuali') ?: null));
    }

    /** The employee chooses for a Cuti Besar reached: take the days or cash it in; HR confirms. */
    public function chooseBesar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'periode_ke' => ['required', 'integer', 'min:1', 'max:20'],
            'keputusan' => ['required', Rule::in(array_keys(CutiBesar::KEPUTUSAN))],
        ]);
        $k = $this->me($request);
        $periode = collect(Cuti::summary($k)['besar']['periode'])->firstWhere('periode_ke', $data['periode_ke']);
        if (! $periode || $periode['status'] !== 'belum_dipilih') {
            throw ValidationException::withMessages(['keputusan' => 'Cuti Besar ini tidak bisa dipilih lagi (sudah dipilih, menunggu HR, atau lewat batas).']);
        }
        $besar = CutiBesar::create([...$data, 'karyawan_id' => $k->id, 'status' => 'diajukan', 'sumber' => 'portal']);
        NotifikasiPengajuan::cutiBesar($besar);
        Inertia::flash('success', 'Pilihan Cuti Besar ('.CutiBesar::KEPUTUSAN[$data['keputusan']].') terkirim ke HR.');

        return back();
    }

    /** Own plafon on a date (the deklarasi form asks as the date changes). */
    public function plafon(Request $request): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']]);
        $k = $this->me($request);
        $bulan = BukuBulan::of(CarbonImmutable::parse($data['tanggal'] ?? CarbonImmutable::today()->toDateString()));

        return response()->json([
            'karyawan' => DeklarasiController::identity($k),
            'bulan_buku' => ['value' => $bulan, 'label' => BukuBulan::label($bulan), 'span' => BukuBulan::span($bulan)],
            ...Usage::summary($k, BukuBulan::tahun($bulan)),
        ]);
    }

    public function storeDeklarasi(Request $request): RedirectResponse
    {
        $request->merge(['karyawan_id' => $request->user()->karyawan_id]);
        [, $data, $items] = DeklarasiController::validateClaim($request);
        $deklarasi = DB::transaction(function () use ($data, $items, $request) {
            $deklarasi = Deklarasi::create([
                ...$data,
                'nomor' => DeklarasiController::nextNomor($data['bulan_buku']),
                'created_by' => $request->user()->id,
                'status' => 'diajukan',
                'sumber' => 'portal',
            ]);
            $deklarasi->items()->createMany($items);
            Attachments::sync($request, $deklarasi, 'deklarasi');

            return $deklarasi;
        });
        NotifikasiPengajuan::deklarasi($deklarasi);
        Inertia::flash('success', "Deklarasi {$deklarasi->nomor} terkirim dan menunggu verifikasi HR. Nominalnya sudah ditahan dari sisa plafon.");

        return back();
    }

    public function storeTrip(Request $request): RedirectResponse
    {
        $request->merge(['karyawan_id' => $request->user()->karyawan_id]);
        $data = TripController::validateTrip($request);
        TripWriter::assertFree($data['karyawan_id'], $data['tanggal_awal'], $data['tanggal_akhir']);
        TripWriter::codeFor($data['jenis']);
        $trip = DB::transaction(function () use ($data, $request) {
            $trip = Trip::create([
                ...$data,
                'status_uang' => TripController::statusUangAwal($data['uang_muka']),
                'created_by' => $request->user()->id,
                'status' => 'diajukan',
                'sumber' => 'portal',
            ]);
            Attachments::sync($request, $trip, 'trip');

            return $trip;
        });
        NotifikasiPengajuan::trip($trip);
        Inertia::flash('success', 'Pengajuan '.Trip::JENIS[$trip->jenis]." ke {$trip->kota_tujuan} terkirim dan menunggu persetujuan HR.");

        return back();
    }

    /** Withdraws an own pengajuan that HR has not decided on yet. */
    public function cancel(Request $request, string $jenis, int $id): RedirectResponse
    {
        $model = $jenis === 'deklarasi' ? Deklarasi::class : Trip::class;
        $item = $model::where('karyawan_id', $request->user()->karyawan_id)->where('status', 'diajukan')->findOrFail($id);
        $item->delete();
        Inertia::flash('success', 'Pengajuan dibatalkan.');

        return back();
    }
}
