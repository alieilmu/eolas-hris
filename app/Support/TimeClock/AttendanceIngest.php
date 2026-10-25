<?php

namespace App\Support\TimeClock;

use App\Models\Employee;
use App\Models\AttendanceDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes clock-ins in (from a machine, a file or HR), matched to employees by their PIN
 * (pin_absen, else NRP), ignoring ones already there (same PIN and time), and then
 * reprocesses the days they touch.
 */
class AttendanceIngest
{
    /**
     * @param  list<array{pin: string, waktu: string, status?: ?int, verify?: ?string}>  $rows
     * @return array{diterima: int, tanpa_karyawan: int, duplikat: int}
     */
    public static function store(array $rows, ?AttendanceDevice $device, string $source, ?int $userId = null, ?string $notes = null): array
    {
        $pins = collect($rows)->pluck('pin')->unique()->values();
        $byPin = Employee::withoutGlobalScope('access')->where(fn ($q) => $q->whereIn('attendance_pin', $pins)->orWhere(fn ($q) => $q->whereNull('attendance_pin')->whereIn('employee_number', $pins)))
            ->get(['id', 'employee_number', 'attendance_pin'])->mapWithKeys(fn (Employee $k) => [$k->pin() => $k->id]);
        $now = now();
        $insert = [];
        $tanpa = 0;
        foreach ($rows as $r) {
            $kid = $byPin[$r['pin']] ?? null;
            $tanpa += $kid ? 0 : 1;
            $insert[] = [
                'attendance_device_id' => $device?->id, 'pin' => $r['pin'], 'employee_id' => $kid,
                'punched_at' => CarbonImmutable::parse($r['punched_at'])->format('Y-m-d H:i:s'),
                'status_code' => $r['status'] ?? null, 'verify' => $r['verify'] ?? null,
                'source' => $source, 'notes' => $notes, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $accepted = 0;
        foreach (array_chunk($insert, 500) as $part) {
            $accepted += DB::table('attendance_logs')->insertOrIgnore($part);
        }

        // Reprocess the days touched (a night shift's CO belongs to the day before).
        $touched = collect($insert)->filter(fn ($r) => $r['employee_id']);
        if ($accepted > 0 && $touched->isNotEmpty()) {
            $dates = $touched->pluck('punched_at')->map(fn ($w) => substr($w, 0, 10))->sort()->values();
            AttendanceProcessor::run(CarbonImmutable::parse($dates->first())->subDay(), CarbonImmutable::parse($dates->last()), $touched->pluck('employee_id')->unique()->values()->all());
        }

        return ['accepted' => $accepted, 'without_employee' => $tanpa, 'duplicate' => count($insert) - $accepted];
    }
}
