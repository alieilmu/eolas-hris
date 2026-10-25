<?php

namespace App\Support\Leave;

use App\Models\Employee;
use App\Models\PointOfHire;
use App\Models\FieldLeaveAllowance;

/**
 * The lumpsum of a Cuti Lapangan: set in Pengaturan Kompensasi per site (work location),
 * POH (the lokasi penerimaan in the NRP) and klasifikasi (Staff / Non Staff) of the employee.
 */
class FieldLeaveAllowanceRate
{
    /** @return array{nominal: ?int, poh: ?string, klasifikasi: ?string, alasan: ?string} */
    public static function for(Employee $k): array
    {
        $k->loadMissing(['profile', 'workLocation']);
        $point_of_hire = self::pointOfHire($k);
        $employee_class = $k->profile?->employee_class;
        $reason = match (true) {
            ! $point_of_hire => 'POH (lokasi penerimaan di NRP) tidak dikenal.',
            ! $employee_class => 'Klasifikasi (Staff / Non Staff) belum diisi di profil.',
            ! $k->work_location_id => 'Lokasi kerja belum diisi.',
            default => null,
        };
        $amount = $reason ? null : FieldLeaveAllowance::where('work_location_id', $k->work_location_id)
            ->where('point_of_hire_id', $point_of_hire->id)
            ->where('employee_class', $employee_class)
            ->value('amount');
        if (! $reason && $amount === null) {
            $reason = "Lumpsum {$k->workLocation?->name} · POH {$point_of_hire->name} · {$employee_class} belum diatur di Pengaturan Kompensasi.";
        }

        return ['amount' => $amount, 'point_of_hire' => $point_of_hire?->name, 'employee_class' => $employee_class, 'reason' => $reason];
    }

    /** The lumpsum stored on a cuti: only a Cuti Lapangan gets one. */
    public static function amount(Employee $k, ?string $category): ?int
    {
        return $category === 'field' ? self::for($k)['amount'] : null;
    }

    /** The employee's POH: the lokasi penerimaan in digits 2–3 of the NRP. */
    public static function pointOfHire(Employee $k): ?PointOfHire
    {
        return PointOfHire::where('code', substr($k->employee_number, 1, 2))->first();
    }
}
