<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manpower\UpdateKaryawanProfileRequest;
use App\Models\Karyawan;
use App\Models\KaryawanAnak;
use App\Models\KaryawanProfile;
use App\Models\PengajuanUpdateData;
use App\Models\PkwtPeriod;
use App\Support\Comben\Entitlement;
use App\Support\Karyawan\Dates;
use App\Support\Karyawan\KaryawanFormOptions;
use App\Support\Karyawan\MasterOptions;
use App\Support\Karyawan\Nrp;
use App\Support\Karyawan\ProfileRules;
use App\Support\Karyawan\ProfileSections;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The full 19-section profile of one employee. */
class KaryawanProfileController extends Controller
{
    /** The latest contract is flagged on the page once it ends within this many days. */
    private const EXPIRING_SOON_DAYS = 30;

    public function show(Karyawan $karyawan): Response
    {
        $karyawan->load('jabatan', 'subGroup', 'subLocation', 'departemen', 'section.departemen', 'workLocation', 'profile.pkwtPeriods', 'profile.anak');
        $profile = $karyawan->profile;
        $stored = $profile?->attributesToArray() ?? [];
        $keys = array_keys(ProfileSections::editableFields());
        $latest = $profile?->latestPkwt();
        $daysLeft = $latest ? Dates::daysUntil($latest->tanggal_berakhir, CarbonImmutable::today()) : null;

        return Inertia::render('Manpower/Karyawan/Profile', [
            'karyawan' => [
                'id' => $karyawan->id,
                'nrp' => $karyawan->nrp,
                'name' => $karyawan->name,
                'email' => $karyawan->email,
                'status' => $karyawan->status,
                'jabatan_id' => $karyawan->jabatan_id,
                // Null while waiting to be placed in Struktur Organisasi.
                'jabatan' => $karyawan->jabatan?->name,
                // Reporting line from the organisation chart.
                'atasan' => ($atasan = $karyawan->jabatan?->atasan()) ? [
                    'jabatan' => $atasan->name,
                    'names' => Karyawan::aktif()->where('jabatan_id', $atasan->id)->orderBy('name')->pluck('name'),
                ] : null,
                'sub_group' => $karyawan->subGroup?->nama,
                // e.g. "Section Workshop · Maintenance".
                'departemen' => $karyawan->jabatan?->unitLabel()
                    ?? collect([$karyawan->section ? "Section {$karyawan->section->name}" : null, $karyawan->placementDepartemen()?->name])->filter()->implode(' · '),
                'work_location' => $karyawan->workLocation?->name,
                'work_location_id' => $karyawan->work_location_id,
                'sub_location' => $karyawan->subLocation?->name,
                'alasan_keluar' => $karyawan->alasan_keluar,
                'tanggal_keluar' => $karyawan->tanggal_keluar?->toDateString(),
            ],
            // Every editable field, null when not filled in yet.
            'profile' => array_map(
                fn (string $key) => $stored[$key] ?? ($key === 'status_pegawai_tetap' ? false : null),
                array_combine($keys, $keys),
            ),
            'computed' => [
                'usia' => $profile?->usia,
                'masa_kerja' => $profile?->masa_kerja,
                'status_akhir_pkwt' => $profile?->status_akhir_pkwt ?? '-',
                // Modul Comben.
                'status_ptkp' => Entitlement::statusPtkp($karyawan),
                'plafon_pengobatan' => self::plafonText($karyawan),
                'lokasi_kerja' => collect([$karyawan->workLocation?->name, $karyawan->subLocation?->name])->filter()->implode(' · '),
                ...Nrp::derived($karyawan),
            ],
            'nrp_warning' => Nrp::yearWarning($karyawan->nrp, $profile?->tanggal_masuk),
            'pengajuan_update' => ($pending = PengajuanUpdateData::where('karyawan_id', $karyawan->id)->where('status', 'diajukan')->first())
                ? ['id' => $pending->id, 'jumlah' => count($pending->perubahan ?? []) + count($pending->anak ?? [])]
                : null,
            // Prefills "Ubah Penempatan"; its choices load when the dialog opens.
            'penempatan' => $karyawan->placement(),
            ...KaryawanFormOptions::props(),
            'pkwt' => ($profile?->pkwtPeriods ?? collect())->map(fn (PkwtPeriod $p) => [
                'id' => $p->id,
                'periode_ke' => $p->periode_ke,
                'label' => PkwtPeriod::label($p->periode_ke),
                'tanggal_kontrak' => $p->tanggal_kontrak->toDateString(),
                'tanggal_berakhir' => $p->tanggal_berakhir->toDateString(),
            ])->values(),
            'anak' => ($profile?->anak ?? collect())->map(fn (KaryawanAnak $a) => [
                'id' => $a->id,
                'urutan' => $a->urutan,
                'nama' => $a->nama,
                'tanggal_lahir' => $a->tanggal_lahir?->toDateString(),
                'pendidikan' => $a->pendidikan,
                'status_bekerja' => $a->status_bekerja,
                'status_menikah' => $a->status_menikah,
            ])->values(),
            'pkwt_expiring_soon' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= self::EXPIRING_SOON_DAYS,
            'sections' => ProfileSections::build(),
            'options' => [
                'max_pkwt' => KaryawanProfile::PKWT_MAX_PERIODS,
                'pendidikan_anak' => MasterOptions::values('pendidikan_anak'),
                'status_bekerja_anak' => ProfileSections::STATUS_BEKERJA_ANAK,
                'status_menikah_anak' => ProfileSections::STATUS_MENIKAH_ANAK,
            ],
        ]);
    }

    /** "Rp 2.250.000 / tahun (L1 · Keluarga)", or why there is none. */
    private static function plafonText(Karyawan $karyawan): string
    {
        $e = Entitlement::shared();
        $plafon = $e->plafonPengobatan($karyawan);
        if ($plafon === null) {
            return 'Belum ada — SubGroup belum punya plafon di Master Data Comben';
        }
        $text = 'Rp '.number_format($plafon, 0, ',', '.').' / tahun ('.$e->pengobatanRule($karyawan)->layer.' · '.(Entitlement::keluarga($karyawan) ? 'Keluarga' : 'Lajang').')';

        return ($warning = $e->warning($karyawan)) ? "{$text} — {$warning}" : $text;
    }

    public function update(UpdateKaryawanProfileRequest $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validated();

        $profile = $karyawan->profile()->updateOrCreate([], $data);

        Inertia::flash('success', 'Profil tersimpan.');
        $warnings = array_values(array_filter([
            ProfileRules::accountHolderWarning($profile->nama_pemilik_rekening, $karyawan->name),
            Nrp::yearWarning($karyawan->nrp, $profile->tanggal_masuk),
        ]));
        if ($warnings) {
            Inertia::flash('warnings', $warnings);
        }

        return back();
    }
}
