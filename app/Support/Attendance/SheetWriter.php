<?php

namespace App\Support\Attendance;

use App\Models\Absensi;
use App\Models\Karyawan;
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
        $karyawanIds = array_values(array_unique(array_map(fn ($c) => (int) $c['karyawan_id'], $changes)));
        $known = Karyawan::whereIn('id', $karyawanIds)->pluck('id')->flip();
        if (count($known) !== count($karyawanIds)) {
            throw ValidationException::withMessages(['changes' => 'Ada karyawan yang sudah tidak ada. Muat ulang halaman lalu coba lagi.']);
        }
        $codeIds = ShiftCode::pluck('id')->flip();
        $worked = Roll::workingDays($karyawanIds);

        $now = now();
        $upserts = [];
        $clears = [];
        $skipped = 0;
        foreach ($changes as $c) {
            $id = $c['shift_code_id'] ?? null;
            if ($id === null) {
                $clears[$c['tanggal']][] = (int) $c['karyawan_id'];

                continue;
            }
            // No codes before joining or from the exit date (Resign, Mutasi, ...) on.
            if (! $worked((int) $c['karyawan_id'], $c['tanggal'])) {
                $skipped++;

                continue;
            }
            if (! $codeIds->has((int) $id)) {
                throw ValidationException::withMessages(['changes' => 'Ada Kode Shift yang sudah dihapus. Muat ulang halaman lalu coba lagi.']);
            }
            $row = ['karyawan_id' => (int) $c['karyawan_id'], 'tanggal' => $c['tanggal'], 'shift_code_id' => (int) $id, 'created_at' => $now, 'updated_at' => $now];
            if ($type === 'absensi') {
                // Edited by hand (or copied / imported): no longer tied to the trip that filled it.
                $sumber = $c['sumber'] ?? 'manual';
                $row += ['sumber' => in_array($sumber, ['manual', 'roster', 'import'], true) ? $sumber : 'manual', 'trip_id' => null];
            }
            $upserts[] = $row;
        }

        $table = $type === 'roster' ? 'rosters' : 'absensis';
        $update = $type === 'roster' ? ['shift_code_id', 'updated_at'] : ['shift_code_id', 'sumber', 'trip_id', 'updated_at'];

        DB::transaction(function () use ($table, $update, $upserts, $clears) {
            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table($table)->upsert($chunk, ['karyawan_id', 'tanggal'], $update);
            }
            foreach ($clears as $tanggal => $ids) {
                DB::table($table)->where('tanggal', $tanggal)->whereIn('karyawan_id', $ids)->delete();
            }
        });

        return ['saved' => count($upserts), 'cleared' => array_sum(array_map('count', $clears)), 'skipped' => $skipped];
    }
}
