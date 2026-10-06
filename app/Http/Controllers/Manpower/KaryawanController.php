<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Support\Karyawan\ColumnGroups;
use App\Support\Karyawan\Dashboard;
use App\Support\Karyawan\KaryawanFormOptions;
use App\Support\Karyawan\KaryawanRow;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\Nrp;
use App\Support\Org\Penempatan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Man Power views and the employee record itself. Admin / HR only (see routes). */
class KaryawanController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Manpower/Karyawan/Dashboard', [
            'dashboard' => Dashboard::build(),
        ]);
    }

    public function dataLengkap(): Response
    {
        $today = CarbonImmutable::today();
        $karyawans = KaryawanRow::activeEmployees();

        return Inertia::render('Manpower/Karyawan/DataLengkap', [
            'rows' => $karyawans->map(fn (Karyawan $k) => KaryawanRow::flatten($k, $today))->values(),
            'groups' => ColumnGroups::build(KaryawanRow::maxAnak($karyawans), ColumnGroups::PKWT_ACTIVE),
            ...KaryawanFormOptions::props(),
            'alasan_options' => MasterOptions::values('alasan_keluar'),
        ]);
    }

    public function keluar(): Response
    {
        $karyawans = Karyawan::keluar()
            ->with(['jabatan', 'departemen', 'section.departemen', 'workLocation', 'profile'])
            ->orderByDesc('tanggal_keluar')
            ->orderBy('nrp')
            ->get();

        return Inertia::render('Manpower/Karyawan/Keluar', [
            'rows' => $karyawans->map(fn (Karyawan $k) => [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'jabatan_name' => $k->jabatan?->name ?? '-',
                'departemen_name' => $k->placementDepartemen()?->name ?? '-',
                'work_location_name' => $k->workLocation?->name,
                'tanggal_masuk' => $k->profile?->tanggal_masuk?->toDateString(),
                'tanggal_keluar' => $k->tanggal_keluar?->toDateString(),
                // Length of service up to the exit date, not today.
                'masa_kerja' => $k->profile?->masaKerjaPada($k->tanggal_keluar ?? CarbonImmutable::today()),
                'alasan_keluar' => $k->alasan_keluar,
                'keterangan_keluar' => $k->keterangan_keluar,
            ])->values(),
            'alasan_options' => MasterOptions::values('alasan_keluar'),
        ]);
    }

    /** New employee with the essentials; the rest is completed on the profile page. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nrp' => ['required', 'string', 'max:20', 'unique:karyawans,nrp'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'nrp.unique' => 'NRP ":input" sudah digunakan.',
        ]);

        if ($error = Nrp::error($data['nrp'])) {
            throw ValidationException::withMessages(['nrp' => $error]);
        }

        // The position is the plan's one matching the placement, or one picked in the chart;
        // without a match the employee waits to be placed in Struktur Organisasi.
        ['attributes' => $placement, 'note' => $note] = Penempatan::resolve($request);
        $karyawan = DB::transaction(fn () => Karyawan::create([...$data, ...$placement, 'status' => Karyawan::STATUS_AKTIF]));

        Inertia::flash('success', "{$karyawan->name} (NRP {$karyawan->nrp}) ditambahkan.".($note ? " {$note}" : ' Lengkapi profilnya lewat ikon profil.'));

        return back();
    }

    /** Permanent: the profile, PKWT history and children go with it. Only for records entered by mistake. */
    public function destroy(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $request->validate([
            'nrp_konfirmasi' => ['required', 'string'],
        ]);

        if (trim($request->string('nrp_konfirmasi')) !== $karyawan->nrp) {
            throw ValidationException::withMessages(['nrp_konfirmasi' => "Ketik NRP {$karyawan->nrp} untuk mengonfirmasi penghapusan."]);
        }

        $karyawan->delete();

        Inertia::flash('success', "Data {$karyawan->name} (NRP {$karyawan->nrp}) dihapus permanen.");

        return back();
    }
}
