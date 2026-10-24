<?php

namespace App\Support\Kehadiran;

use App\Models\LemburAturan;
use App\Models\ShiftCode;
use App\Models\SubGroup;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * The starting point of Kehadiran & Lembur, filled only where nothing was set yet:
 *  - hours of the work shifts: D 07.00–19.00 and N 19.00–07.00 (site, break 1 hour),
 *    and NS 08.00–17.00 (office, break 12.00–13.00);
 *  - working time: Head Office 5 days × 8 hours, sites the ESDM roster scheme with
 *    7 normal hours a day;
 *  - overtime: everyone at Head Office; at the sites only the non-staff SubGroups
 *    (layer 1: operator, mekanik, welder, …).
 */
class KehadiranDefaults
{
    private const JAM = [
        'D' => ['07:00', '19:00', '12:00', '13:00'],
        'N' => ['19:00', '07:00', '00:00', '01:00'],
    ];

    public static function seed(): void
    {
        foreach (self::JAM as $kode => [$masuk, $keluar, $mulai, $selesai]) {
            ShiftCode::where('kode', $kode)->whereNull('jam_masuk')
                ->update(['jam_masuk' => $masuk, 'jam_keluar' => $keluar, 'istirahat_mulai' => $mulai, 'istirahat_selesai' => $selesai]);
        }
        ShiftCode::firstOrCreate(['kode' => 'NS'], [
            'dws' => 'NORMAL', 'keterangan' => 'Normal office 08.00–17.00', 'jenis' => 'hadir',
            'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'istirahat_mulai' => '12:00', 'istirahat_selesai' => '13:00',
            'order' => (ShiftCode::max('order') ?? 0) + 1,
        ]);

        WorkLocation::where('type', '!=', WorkLocation::TYPE_HEAD_OFFICE)->where('skema_kerja', '5_hari')->where('jam_kerja_harian', 8)
            ->update(['skema_kerja' => 'esdm', 'jam_kerja_harian' => 7]);

        LemburAturan::current();

        $now = now();
        foreach (SubGroup::all() as $g) {
            foreach ([WorkLocation::TYPE_HEAD_OFFICE => true, 'Site' => $g->layer <= 1] as $tipe => $berhak) {
                DB::table('sub_group_lembur')->insertOrIgnore(['sub_group_id' => $g->id, 'tipe_lokasi' => $tipe, 'berhak' => $berhak, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
