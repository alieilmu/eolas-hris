<?php

namespace App\Support\Attendance;

use App\Models\ShiftCode;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps Absensi in step with Trip & Leave: a trip writes the Dinas / Cuti code
 * into each of its days, and takes them back when it moves or is removed.
 */
class TripWriter
{
    /** Throws when the employee already has a trip on one of these days. */
    public static function assertFree(int $karyawanId, string $from, string $to, ?Trip $current = null): void
    {
        // Waiting ones count too: two pengajuan for the same days cannot both be approved.
        $clash = Trip::where('karyawan_id', $karyawanId)
            ->where('status', '!=', 'ditolak')
            ->overlapping($from, $to)
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->orderBy('tanggal_awal')
            ->first();
        if ($clash) {
            $range = $clash->tanggal_awal->format(SheetExcel::DATE_FORMAT).' – '.$clash->tanggal_akhir->format(SheetExcel::DATE_FORMAT);
            throw ValidationException::withMessages(['tanggal_awal' => 'Karyawan ini sudah tercatat '.Trip::JENIS[$clash->jenis]." ke {$clash->kota_tujuan} pada {$range}."]);
        }
    }

    /** The code a trip of this kind writes; throws when Master Data has none. */
    public static function codeFor(string $jenis): ShiftCode
    {
        return ShiftCode::forJenis($jenis)
            ?? throw ValidationException::withMessages(['jenis' => 'Belum ada Kode Shift berjenis "'.ShiftCode::JENIS[$jenis].'". Tambahkan dulu di Master Data Attendance.']);
    }

    /** Writes the trip's days into Absensi (replacing what was there). */
    public static function fill(Trip $trip): void
    {
        if ($trip->status !== 'disetujui') {
            return; // waiting for HR, or rejected: not in Absensi
        }
        $code = self::codeFor($trip->jenis);
        $now = now();
        DB::transaction(function () use ($trip, $code, $now) {
            DB::table('absensis')->where('trip_id', $trip->id)->delete();
            $rows = [];
            for ($d = $trip->tanggal_awal; $d->lte($trip->tanggal_akhir); $d = $d->addDay()) {
                $rows[] = [
                    'karyawan_id' => $trip->karyawan_id,
                    'tanggal' => $d->toDateString(),
                    'shift_code_id' => $code->id,
                    'sumber' => 'trip',
                    'trip_id' => $trip->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('absensis')->upsert($chunk, ['karyawan_id', 'tanggal'], ['shift_code_id', 'sumber', 'trip_id', 'updated_at']);
            }
        });
    }
}
