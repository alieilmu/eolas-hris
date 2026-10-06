<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\ProfileRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Recording an exit, and undoing one. The record is kept; it only leaves the active workforce. */
class KaryawanKeluarController extends Controller
{
    public function store(Request $request, Karyawan $karyawan): RedirectResponse
    {
        if ($karyawan->status === Karyawan::STATUS_KELUAR) {
            throw ValidationException::withMessages(['alasan' => 'Karyawan ini sudah tercatat keluar.']);
        }

        $alasanOptions = MasterOptions::values('alasan_keluar');
        $data = $request->validate([
            'alasan' => ['required', Rule::in($alasanOptions)],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ], [
            'alasan.required' => 'Alasan keluar wajib dipilih.',
            'alasan.in' => 'Alasan keluar wajib dipilih ('.implode(', ', $alasanOptions).').',
        ]);

        $masuk = $karyawan->profile?->tanggal_masuk;
        if ($masuk && $data['tanggal'] < $masuk->toDateString()) {
            throw ValidationException::withMessages([
                'tanggal' => "Tanggal keluar tidak boleh sebelum tanggal masuk ({$masuk->format('d.m.Y')}).",
            ]);
        }

        $karyawan->update([
            'status' => Karyawan::STATUS_KELUAR,
            'alasan_keluar' => $data['alasan'],
            'tanggal_keluar' => $data['tanggal'],
            'keterangan_keluar' => $data['keterangan'] ?? null,
        ]);

        Inertia::flash('success', "{$karyawan->name} dicatat keluar ({$data['alasan']}). Datanya kini ada di menu Karyawan Keluar.");

        return back();
    }

    /** Back to Aktif in the same Jabatan, e.g. when the exit was recorded by mistake. */
    public function destroy(Karyawan $karyawan): RedirectResponse
    {
        if ($karyawan->status !== Karyawan::STATUS_KELUAR) {
            throw ValidationException::withMessages(['karyawan' => 'Karyawan ini tidak berstatus keluar.']);
        }

        // The old seat may have been filled in the meantime: then they wait to be placed again.
        $full = $karyawan->jabatan_id ? ProfileRules::capacityError($karyawan->jabatan_id, $karyawan->id) : null;

        $karyawan->update([
            'status' => Karyawan::STATUS_AKTIF,
            'alasan_keluar' => null,
            'tanggal_keluar' => null,
            'keterangan_keluar' => null,
            ...($full ? ['jabatan_id' => null] : []),
        ]);

        Inertia::flash('success', "{$karyawan->name} aktif kembali.".($full ? " {$full} Tempatkan lagi lewat Struktur Organisasi." : ''));

        return back();
    }
}
