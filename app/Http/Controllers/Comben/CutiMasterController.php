<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\CutiAturan;
use App\Models\CutiLapangan;
use App\Models\LokasiPenerimaan;
use App\Models\LumpsumCuti;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Karyawan\MasterOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Master Data Comben → Aturan Cuti, Cuti Lapangan per lokasi / sub lokasi, and its lumpsum. */
class CutiMasterController extends Controller
{
    public function updateAturan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'jatah_tahunan' => ['required', 'integer', 'min:0', 'max:60'],
            'masa_aktif_tahun' => ['required', 'integer', 'min:1', 'max:5'],
            'besar_interval_tahun' => ['required', 'integer', 'min:1', 'max:20'],
            'besar_hari' => ['required', 'integer', 'min:0', 'max:120'],
            'besar_gapok_diambil' => ['required', 'numeric', 'min:0', 'max:12'],
            'besar_gapok_diuangkan' => ['required', 'numeric', 'min:0', 'max:12'],
            'besar_batas_bulan' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        CutiAturan::current()->update($data);
        CutiAturan::forget();
        Inertia::flash('success', 'Aturan cuti disimpan; saldo semua karyawan dihitung ulang dengan aturan ini.');

        return back();
    }

    public function storeLapangan(Request $request): RedirectResponse
    {
        CutiLapangan::create($this->lapanganData($request));
        Inertia::flash('success', 'Cuti lapangan ditambahkan.');

        return back();
    }

    public function updateLapangan(Request $request, CutiLapangan $lapangan): RedirectResponse
    {
        $lapangan->update($this->lapanganData($request, $lapangan));
        Inertia::flash('success', 'Cuti lapangan diperbarui.');

        return back();
    }

    public function destroyLapangan(CutiLapangan $lapangan): RedirectResponse
    {
        $lapangan->delete();
        Inertia::flash('success', 'Cuti lapangan dihapus.');

        return back();
    }

    /** The lumpsum of one site: per POH (lokasi penerimaan) and klasifikasi; empty = none. */
    public function updateLumpsum(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')],
            'nominal' => ['present', 'array'],
            'nominal.*' => ['array'],
            'nominal.*.*' => ['nullable', 'integer', 'min:0', 'max:999999999'],
        ], [], ['nominal.*.*' => 'Nominal']);
        $poh = LokasiPenerimaan::pluck('id')->all();
        $klasifikasi = MasterOptions::values('klasifikasi');
        DB::transaction(function () use ($data, $poh, $klasifikasi) {
            LumpsumCuti::where('work_location_id', $data['work_location_id'])->delete();
            foreach ($data['nominal'] as $pohId => $values) {
                foreach ($values as $k => $nominal) {
                    if ($nominal !== null && $nominal !== '' && in_array((int) $pohId, $poh, true) && in_array($k, $klasifikasi, true)) {
                        LumpsumCuti::create(['work_location_id' => $data['work_location_id'], 'lokasi_penerimaan_id' => (int) $pohId, 'klasifikasi' => $k, 'nominal' => (int) $nominal]);
                    }
                }
            }
        });
        Inertia::flash('success', 'Lumpsum cuti lapangan '.WorkLocation::whereKey($data['work_location_id'])->value('name').' disimpan; berlaku untuk pengajuan cuti lapangan berikutnya.');

        return back();
    }

    private function lapanganData(Request $request, ?CutiLapangan $current = null): array
    {
        $data = $request->validate([
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')],
            'sub_location_id' => ['nullable', 'integer', Rule::exists('sub_locations', 'id')],
            'hari_cuti' => ['required', 'integer', 'min:0', 'max:90'],
            'hari_berangkat' => ['required', 'integer', 'min:0', 'max:10'],
            'hari_kembali' => ['required', 'integer', 'min:0', 'max:10'],
        ], [], ['work_location_id' => 'Lokasi', 'hari_cuti' => 'Hari cuti', 'hari_berangkat' => 'Hari perjalanan berangkat', 'hari_kembali' => 'Hari perjalanan kembali']);
        if ($data['sub_location_id'] && SubLocation::whereKey($data['sub_location_id'])->value('work_location_id') !== (int) $data['work_location_id']) {
            throw ValidationException::withMessages(['sub_location_id' => 'Sub lokasi itu bukan bagian dari lokasi yang dipilih.']);
        }
        $taken = CutiLapangan::where('work_location_id', $data['work_location_id'])
            ->where(fn ($q) => $data['sub_location_id'] ? $q->where('sub_location_id', $data['sub_location_id']) : $q->whereNull('sub_location_id'))
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['work_location_id' => 'Lokasi / sub lokasi ini sudah punya aturan cuti lapangan.']);
        }

        return $data;
    }
}
