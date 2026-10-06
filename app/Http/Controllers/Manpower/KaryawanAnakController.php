<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\KaryawanAnak;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\ProfileSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Children of one employee. No cap on how many — four columns in the old sheet were a layout limit. */
class KaryawanAnakController extends Controller
{
    public function store(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $profile = $karyawan->profile()->firstOrCreate();
        $profile->anak()->create([...$data, 'urutan' => $profile->anak()->count() + 1]);

        return back();
    }

    public function update(Request $request, Karyawan $karyawan, KaryawanAnak $anak): RedirectResponse
    {
        $this->ensureBelongs($karyawan, $anak);

        $anak->update($request->validate($this->rules()));

        return back();
    }

    public function destroy(Karyawan $karyawan, KaryawanAnak $anak): RedirectResponse
    {
        $this->ensureBelongs($karyawan, $anak);

        DB::transaction(function () use ($anak) {
            $profile = $anak->profile;
            $anak->delete();

            // Keep "Anak ke-N" contiguous.
            foreach ($profile->anak()->get()->values() as $i => $child) {
                if ($child->urutan !== $i + 1) {
                    $child->update(['urutan' => $i + 1]);
                }
            }
        });

        return back();
    }

    private function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
            'pendidikan' => ['nullable', Rule::in(MasterOptions::values('pendidikan_anak'))],
            'status_bekerja' => ['nullable', Rule::in(ProfileSections::STATUS_BEKERJA_ANAK)],
            'status_menikah' => ['nullable', Rule::in(ProfileSections::STATUS_MENIKAH_ANAK)],
        ];
    }

    private function ensureBelongs(Karyawan $karyawan, KaryawanAnak $anak): void
    {
        abort_unless($anak->profile->karyawan_id === $karyawan->id, 404);
    }
}
