<?php

namespace App\Support\Cuti;

use App\Models\Karyawan;
use App\Models\LokasiPenerimaan;
use App\Models\LumpsumCuti;

/**
 * The lumpsum of a Cuti Lapangan: set in Master Data Comben per site (work location),
 * POH (the lokasi penerimaan in the NRP) and klasifikasi (Staff / Non Staff) of the employee.
 */
class Lumpsum
{
    /** @return array{nominal: ?int, poh: ?string, klasifikasi: ?string, alasan: ?string} */
    public static function for(Karyawan $k): array
    {
        $k->loadMissing(['profile', 'workLocation']);
        $poh = self::poh($k);
        $klasifikasi = $k->profile?->klasifikasi;
        $alasan = match (true) {
            ! $poh => 'POH (lokasi penerimaan di NRP) tidak dikenal.',
            ! $klasifikasi => 'Klasifikasi (Staff / Non Staff) belum diisi di profil.',
            ! $k->work_location_id => 'Lokasi kerja belum diisi.',
            default => null,
        };
        $nominal = $alasan ? null : LumpsumCuti::where('work_location_id', $k->work_location_id)
            ->where('lokasi_penerimaan_id', $poh->id)
            ->where('klasifikasi', $klasifikasi)
            ->value('nominal');
        if (! $alasan && $nominal === null) {
            $alasan = "Lumpsum {$k->workLocation?->name} · POH {$poh->nama} · {$klasifikasi} belum diatur di Master Data Comben.";
        }

        return ['nominal' => $nominal, 'poh' => $poh?->nama, 'klasifikasi' => $klasifikasi, 'alasan' => $alasan];
    }

    /** The lumpsum stored on a cuti: only a Cuti Lapangan gets one. */
    public static function nominal(Karyawan $k, ?string $kategori): ?int
    {
        return $kategori === 'lapangan' ? self::for($k)['nominal'] : null;
    }

    /** The employee's POH: the lokasi penerimaan in digits 2–3 of the NRP. */
    public static function poh(Karyawan $k): ?LokasiPenerimaan
    {
        return LokasiPenerimaan::where('kode', substr($k->nrp, 1, 2))->first();
    }
}
