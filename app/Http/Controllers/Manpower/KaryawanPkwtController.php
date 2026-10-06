<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\KaryawanProfile;
use App\Models\PkwtPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Contract periods (PKWT I–VI) of one employee. */
class KaryawanPkwtController extends Controller
{
    private const DATE_RULES = [
        'tanggal_kontrak' => ['required', 'date_format:Y-m-d'],
        'tanggal_berakhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_kontrak'],
    ];

    private const MESSAGES = [
        'tanggal_berakhir.after_or_equal' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal kontrak.',
    ];

    /** Adds the next period — also how a contract is extended from the dashboard. */
    public function store(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate(self::DATE_RULES, self::MESSAGES);

        // The profile row must exist before contracts can hang off it.
        $profile = $karyawan->profile()->firstOrCreate();
        $count = $profile->pkwtPeriods()->count();

        if ($count >= KaryawanProfile::PKWT_MAX_PERIODS) {
            throw ValidationException::withMessages([
                'tanggal_kontrak' => 'Maksimal '.KaryawanProfile::PKWT_MAX_PERIODS.' periode PKWT. Gunakan Pengangkatan Pegawai untuk status tetap.',
            ]);
        }

        $profile->pkwtPeriods()->create([...$data, 'periode_ke' => $count + 1]);

        Inertia::flash('success', PkwtPeriod::label($count + 1)." untuk {$karyawan->name} tersimpan.");

        return back();
    }

    public function update(Request $request, Karyawan $karyawan, PkwtPeriod $pkwt): RedirectResponse
    {
        $this->ensureBelongs($karyawan, $pkwt);

        $pkwt->update($request->validate(self::DATE_RULES, self::MESSAGES));

        return back();
    }

    public function destroy(Karyawan $karyawan, PkwtPeriod $pkwt): RedirectResponse
    {
        $this->ensureBelongs($karyawan, $pkwt);

        DB::transaction(function () use ($pkwt) {
            $profile = $pkwt->profile;
            $pkwt->delete();

            // Keep numbering contiguous so the next period gets the right label.
            foreach ($profile->pkwtPeriods()->get()->values() as $i => $period) {
                if ($period->periode_ke !== $i + 1) {
                    $period->update(['periode_ke' => $i + 1]);
                }
            }
        });

        return back();
    }

    private function ensureBelongs(Karyawan $karyawan, PkwtPeriod $pkwt): void
    {
        abort_unless($pkwt->profile->karyawan_id === $karyawan->id, 404);
    }
}
