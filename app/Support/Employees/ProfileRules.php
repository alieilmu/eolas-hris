<?php

namespace App\Support\Employees;

use App\Models\Position;
use App\Models\Employee;
use App\Models\EmployeeProfile;

/** Checks shared by the Excel import and the profile form. */
class ProfileRules
{
    /** Official numbers: format-checked and unique across employees. */
    public const NUMBERED_FIELDS = [
        'national_id_number' => 'NIK (KTP)',
        'tax_id_number' => 'NPWP',
        'bpjs_employment_number' => 'BPJS Ketenagakerjaan',
        'bpjs_health_number' => 'BPJS Kesehatan',
        'sap_employee_number' => 'NRP SAP',
    ];

    /** First format error among the numbers present in $data, or null. */
    public static function numberFormatError(array $data): ?string
    {
        $digits = fn (string $key) => isset($data[$key]) && $data[$key] !== '' ? trim((string) $data[$key]) : null;

        if (($v = $digits('national_id_number')) !== null && ! preg_match('/^\d{16}$/', $v)) {
            return 'NIK harus 16 digit angka.';
        }
        if (($v = $digits('tax_id_number')) !== null && ! preg_match('/^\d{15,16}$/', $v)) {
            return 'NPWP harus 15 atau 16 digit angka.';
        }
        if (($v = $digits('bpjs_employment_number')) !== null && ! ctype_digit($v)) {
            return 'Nomor BPJS Ketenagakerjaan harus berupa angka.';
        }
        if (($v = $digits('bpjs_health_number')) !== null && ! ctype_digit($v)) {
            return 'Nomor BPJS Kesehatan harus berupa angka.';
        }

        return null;
    }

    /** First official number in $data already used by another employee, as a message; or null. */
    public static function duplicateNumberError(array $data, ?int $exceptKaryawanId): ?string
    {
        foreach (self::NUMBERED_FIELDS as $key => $label) {
            if (! isset($data[$key]) || $data[$key] === '') {
                continue;
            }

            $clash = EmployeeProfile::with('employee')
                ->where($key, trim((string) $data[$key]))
                ->when($exceptKaryawanId, fn ($q) => $q->where('employee_id', '!=', $exceptKaryawanId))
                ->first();

            if ($clash) {
                return "{$label} \"{$data[$key]}\" sudah dipakai karyawan \"{$clash->employee->name}\".";
            }
        }

        return null;
    }

    /**
     * The bank account holder should match the employee. A mismatch is a
     * non-blocking warning — joint accounts and maiden names exist — but a silent
     * one risks a misdirected transfer.
     */
    public static function accountHolderWarning(?string $holderName, string $karyawanName): ?string
    {
        if (! $holderName) {
            return null;
        }

        $normalize = fn (string $s) => preg_replace('/[^a-z]/', '', strtolower($s));
        $holder = $normalize($holderName);
        $employee = $normalize($karyawanName);

        if ($holder === '' || str_contains($holder, $employee) || str_contains($employee, $holder)) {
            return null;
        }

        return "Nama pemilik rekening (\"{$holderName}\") berbeda dari nama karyawan (\"{$karyawanName}\") — pastikan tidak salah transfer.";
    }

    /**
     * A position of SubGroup layer 3 or higher (Section Head and up) takes one
     * active employee. Below that it can be shared (headcount is a Man Power
     * Planning concern). Positions are per work location, so a Section Head at
     * HO and one at a site do not compete.
     */
    public static function capacityError(int $positionId, ?int $exceptKaryawanId = null): ?string
    {
        $position = Position::with('jobLevel')->find($positionId);

        if (! $position) {
            return 'Jabatan tidak ditemukan.';
        }
        if (! $position->jobLevel?->isSingleHolder()) {
            return null;
        }

        $holder = Employee::active()
            ->where('position_id', $positionId)
            ->when($exceptKaryawanId, fn ($q) => $q->where('id', '!=', $exceptKaryawanId))
            ->first();

        return $holder
            ? "Jabatan \"{$position->name}\" (SubGroup {$position->jobLevel->name}) hanya bisa diisi 1 karyawan aktif. Sudah ada \"{$holder->name}\" di posisi ini."
            : null;
    }
}
