<?php

namespace App\Support\Karyawan;

use App\Models\Karyawan;
use App\Models\PkwtPeriod;
use App\Support\Comben\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Collapses an employee plus profile and child tables into one flat row, keyed
 * to match ColumnGroups. Expects the relations of activeEmployees() to be loaded.
 */
class KaryawanRow
{
    /** A running contract is flagged once it ends within this many months. */
    public const PKWT_WARNING_MONTHS = 2;

    /** Profile columns that are not data fields of the record. */
    private const SKIP = ['id', 'karyawan_id', 'created_at', 'updated_at'];

    /** What flatten() reads. */
    public const RELATIONS = [
        'jabatan', 'subGroup', 'subLocation', 'workLocation', 'bod', 'divisi.bod', 'departemen.divisi.bod', 'section.departemen.divisi.bod',
        'profile.pkwtPeriods', 'profile.anak',
    ];

    /**
     * Everyone shown in Data Lengkap and the export: all but exited employees,
     * who have their own view.
     *
     * @return Collection<int, Karyawan>
     */
    public static function activeEmployees(): Collection
    {
        return Karyawan::where('status', '!=', Karyawan::STATUS_KELUAR)
            ->with(self::RELATIONS)
            ->orderBy('nrp')
            ->get();
    }

    /** Most children any of these employees has — decides how many child columns to show. */
    public static function maxAnak(Collection $karyawans): int
    {
        return $karyawans->max(fn (Karyawan $k) => $k->profile?->anak->count() ?? 0) ?? 0;
    }

    /** @return array<string, string|int|float|bool|null> */
    public static function flatten(Karyawan $k, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $p = $k->profile;
        // The employee's own placement; the units above the one given are filled in.
        $departemen = $k->placementDepartemen();
        $divisi = $k->divisi ?? $departemen?->divisi;

        $row = [
            'id' => $k->id,
            'nrp' => $k->nrp,
            'name' => $k->name,
            'email' => $k->email,
            'status' => $k->status,
            // Links the row to its card in the organisation chart (empty: waiting to be placed).
            'jabatan_id' => $k->jabatan_id,
            'work_location_id' => $k->work_location_id,
            'work_location_name' => $k->workLocation?->name,
            'sub_location_name' => $k->subLocation?->name,
            'sub_group' => $k->subGroup?->nama,
            // Empty while waiting to be placed in Struktur Organisasi.
            'jabatan_name' => $k->jabatan?->name,
            'bod_name' => ($k->bod ?? $divisi?->bod)?->name,
            'divisi_name' => $divisi?->name,
            'departemen_name' => $departemen?->name,
            'section_name' => $k->section?->name,
            'bidang' => $k->bidang,
            // Jenis karyawan, tempat penerimaan, status asal.
            ...Nrp::derived($k),
        ];

        if (! $p) {
            // No profile filled in yet — the base columns above are all there is.
            return $row + [
                'usia' => null,
                'masa_kerja' => null,
                'status_akhir_pkwt' => '-',
                'pkwt_terakhir_hampir_habis' => false,
                'plafon_pengobatan' => Entitlement::shared()->plafonPengobatan($k),
            ];
        }

        foreach (array_diff_key($p->attributesToArray(), array_flip(self::SKIP)) as $key => $value) {
            $row[$key] = $value;
        }

        $row['usia'] = $p->usia;
        $row['masa_kerja'] = $p->masa_kerja;
        $row['status_akhir_pkwt'] = $p->status_akhir_pkwt;
        $row['plafon_pengobatan'] = Entitlement::shared()->plafonPengobatan($k);

        // On screen a single contract is shown: the one that ends last. That is the
        // running one normally, an already-signed extension when there is one, and a
        // lapsed contract when nothing follows it — so HR can follow up.
        $latest = $p->latestPkwt();
        $row['pkwt_terakhir_periode'] = $latest ? PkwtPeriod::label($latest->periode_ke) : null;
        $row['pkwt_terakhir_kontrak'] = $latest?->tanggal_kontrak->toDateString();
        $row['pkwt_terakhir_berakhir'] = $latest?->tanggal_berakhir->toDateString();
        $row['pkwt_terakhir_sisa_hari'] = $latest ? Dates::daysUntil($latest->tanggal_berakhir, $today) : null;
        $row['pkwt_terakhir_hampir_habis'] = $latest
            && Dates::isWithinMonths($latest->tanggal_berakhir, self::PKWT_WARNING_MONTHS, $today);

        // Per-period columns, used by the Excel export.
        foreach ($p->pkwtPeriods as $period) {
            $row["pkwt_{$period->periode_ke}_kontrak"] = $period->tanggal_kontrak->toDateString();
            $row["pkwt_{$period->periode_ke}_berakhir"] = $period->tanggal_berakhir->toDateString();
        }

        foreach ($p->anak as $child) {
            $n = $child->urutan;
            $row["anak_{$n}_nama"] = $child->nama;
            $row["anak_{$n}_tanggal_lahir"] = $child->tanggal_lahir?->toDateString();
            $row["anak_{$n}_pendidikan"] = $child->pendidikan;
            $row["anak_{$n}_status_bekerja"] = $child->status_bekerja;
            $row["anak_{$n}_status_menikah"] = $child->status_menikah;
        }

        return $row;
    }
}
