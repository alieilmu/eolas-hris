<?php

namespace App\Support\Time;

use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps Absensi in step with Cuti & Dinas: a trip writes the Dinas / Cuti code
 * into each of its days, and takes them back when it moves or is removed.
 */
class LeaveTravelWriter
{
    /** Throws when the employee already has a trip on one of these days. */
    public static function assertFree(int $employeeId, string $from, string $to, ?LeaveTravelRequest $current = null): void
    {
        // Waiting ones count too: two pengajuan for the same days cannot both be approved.
        $clash = LeaveTravelRequest::where('employee_id', $employeeId)
            ->where('status', '!=', 'rejected')
            ->overlapping($from, $to)
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->orderBy('start_date')
            ->first();
        if ($clash) {
            $range = $clash->start_date->format(SheetExcel::DATE_FORMAT).' – '.$clash->end_date->format(SheetExcel::DATE_FORMAT);
            throw ValidationException::withMessages(['start_date' => 'Karyawan ini sudah tercatat '.LeaveTravelRequest::JENIS[$clash->type]." ke {$clash->destination_city} pada {$range}."]);
        }
    }

    /** The code a trip of this kind writes; throws when Master Data has none. */
    public static function codeFor(string $type): ShiftCode
    {
        return ShiftCode::forType($type)
            ?? throw ValidationException::withMessages(['type' => 'Belum ada Kode Shift berjenis "'.ShiftCode::JENIS[$type].'". Tambahkan dulu di Pengaturan Waktu & Kehadiran.']);
    }

    /** Writes the trip's days into Absensi (replacing what was there). */
    public static function fill(LeaveTravelRequest $leave_travel): void
    {
        if ($leave_travel->status !== 'approved') {
            return; // waiting for HR, or rejected: not in Absensi
        }
        $code = self::codeFor($leave_travel->type);
        $now = now();
        DB::transaction(function () use ($leave_travel, $code, $now) {
            DB::table('attendance_records')->where('leave_travel_request_id', $leave_travel->id)->delete();
            $rows = [];
            for ($d = $leave_travel->start_date; $d->lte($leave_travel->end_date); $d = $d->addDay()) {
                $rows[] = [
                    'employee_id' => $leave_travel->employee_id,
                    'date' => $d->toDateString(),
                    'shift_code_id' => $code->id,
                    'source' => 'leave_travel',
                    'leave_travel_request_id' => $leave_travel->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('attendance_records')->upsert($chunk, ['employee_id', 'date'], ['shift_code_id', 'source', 'leave_travel_request_id', 'updated_at']);
            }
        });
    }
}
