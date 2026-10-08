<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\PengajuanUpdateData;
use App\Support\Karyawan\DataUpdate;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\ProfileSections;
use App\Support\Lampiran\Attachments;
use App\Support\Notifikasi\Pengajuan as NotifikasiPengajuan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Portal Karyawan → Data Saya: the employee's own data, and changes to it sent to HR. */
class DataController extends Controller
{
    private function me(Request $request): Karyawan
    {
        return Karyawan::with(['jabatan', 'workLocation', 'profile.anak'])->findOrFail($request->user()->karyawan_id);
    }

    public function show(Request $request): Response
    {
        $k = $this->me($request);
        $history = PengajuanUpdateData::with(['lampirans', 'verifier'])->where('karyawan_id', $k->id)->latest('id')->limit(10)->get();

        return Inertia::render('Portal/Data', [
            'karyawan' => ['nrp' => $k->nrp, 'name' => $k->name, 'jabatan' => $k->jabatan?->name, 'work_location' => $k->workLocation?->name],
            ...DataUpdate::current($k),
            'sections' => DataUpdate::sections(),
            'options' => [
                'pendidikan_anak' => MasterOptions::values('pendidikan_anak'),
                'status_bekerja_anak' => ProfileSections::STATUS_BEKERJA_ANAK,
                'status_menikah_anak' => ProfileSections::STATUS_MENIKAH_ANAK,
            ],
            'menunggu' => ($pending = $history->firstWhere('status', 'diajukan')) ? DataUpdate::row($pending) : null,
            'riwayat' => $history->where('status', '!=', 'diajukan')->map(fn (PengajuanUpdateData $p) => DataUpdate::row($p))->values(),
            'lampiran_limits' => Attachments::limits(),
        ]);
    }

    /** One request at a time: it waits for HR, then the data changes. */
    public function store(Request $request): RedirectResponse
    {
        $k = $this->me($request);
        if (PengajuanUpdateData::where('karyawan_id', $k->id)->where('status', 'diajukan')->exists()) {
            throw ValidationException::withMessages(['perubahan' => 'Masih ada pengajuan yang menunggu HR. Batalkan dulu bila ingin mengubahnya.']);
        }
        [$perubahan, $anak] = DataUpdate::diff($request, $k);
        $request->validate(Attachments::rules(), Attachments::messages());

        $p = DB::transaction(function () use ($k, $perubahan, $anak, $request) {
            $p = PengajuanUpdateData::create([
                'karyawan_id' => $k->id,
                'perubahan' => $perubahan,
                'anak' => $anak,
                'catatan' => $request->input('catatan') ?: null,
                'status' => 'diajukan',
                'created_by' => $request->user()->id,
            ]);
            Attachments::sync($request, $p, 'update-data');

            return $p;
        });
        NotifikasiPengajuan::updateData($p);
        Inertia::flash('success', 'Pengajuan update data terkirim ('.(count($perubahan) + count($anak)).' perubahan). Data berubah setelah disetujui HR.');

        return back();
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        PengajuanUpdateData::where('karyawan_id', $request->user()->karyawan_id)->where('status', 'diajukan')->findOrFail($id)->delete();
        Inertia::flash('success', 'Pengajuan update data dibatalkan.');

        return back();
    }
}
