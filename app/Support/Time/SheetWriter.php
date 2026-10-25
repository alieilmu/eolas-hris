<?php

namespace App\Support\Time;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShiftCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Saves a batch of edited cells of Roster or Absensi. */
class SheetWriter
{
    /**
     * @param  list<array{karyawan_id: int, tanggal: string, shift_code_id: int|null, sumber?: string}>  $changes
     * @return array{saved: int, cleared: int, skipped: int}
     */
    public static function save(string $type, array $changes): array
    {
        $employeeIds = array_values(array_unique(array_map(fn ($c) => (int) $c['employee_id'], $changes)));
        $known = Employee::whereIn('id', $employeeIds)->pluck('id')->flip();
        if (count($known) !== count($employeeIds)) {
            throw ValidationException::withMessages(['changes' => 'Ada karyawan yang sudah tidak ada. Muat ulang halaman lalu coba lagi.']);
        }
        $codeIds = ShiftCode::pluck('id')->flip();
        $worked = Roll::workingDays($employeeIds);

        $now = now();
        $upserts = [];
        $clears = [];
        $skipped = 0;
        foreach ($changes as $c) {
            $id = $c['shift_code_id'] ?? null;
            if ($id === null) {
                $clears[$c['date']][] = (int) $c['employee_id'];

                continue;
            }
            // No codes before joining or from the exit date (Resign, Mutasi, ...) on.
            if (! $worked((int) $c['employee_id'], $c['date'])) {
                $skipped++;

                continue;
            }
            if (! $codeIds->has((int) $id)) {
                throw ValidationException::withMessages(['changes' => 'Ada Kode Shift yang sudah dihapus. Muat ulang halaman lalu coba lagi.']);
            }
            $row = ['employee_id' => (int) $c['employee_id'], 'date' => $c['date'], 'shift_code_id' => (int) $id, 'created_at' => $now, 'updated_at' => $now];
            if ($type === 'attendance') {
                // Edited by hand (or copied / imported): no longer tied to the trip that filled it.
                $source = $c['source'] ?? 'manual';
                $row += ['source' => in_array($source, ['manual', 'schedule', 'import'], true) ? $source : 'manual', 'leave_travel_request_id' => null];
            }
            $upserts[] = $row;
        }

        $table = $type === 'schedule' ? 'shift_schedules' : 'attendance_records';
        $update = $type === 'schedule' ? ['shift_code_id', 'updated_at'] : ['shift_code_id', 'source', 'leave_travel_request_id', 'updated_at'];

        DB::transaction(function () use ($table, $update, $upserts, $clears) {
            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table($table)->upsert($chunk, ['employee_id', 'date'], $update);
            }
            foreach ($clears as $date => $ids) {
                DB::table($table)->where('date', $date)->whereIn('employee_id', $ids)->delete();
            }
        });

        return ['saved' => count($upserts), 'cleared' => array_sum(array_map('count', $clears)), 'skipped' => $skipped];
    }
}
