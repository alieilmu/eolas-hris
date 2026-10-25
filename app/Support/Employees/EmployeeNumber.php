<?php

namespace App\Support\Employees;

use App\Models\EmployeeCategory;
use App\Models\Employee;
use App\Models\PointOfHire;
use Carbon\CarbonInterface;

/**
 * NRP = J LL YY NNN (8 digits):
 *   J   jenis karyawan      (1 Karyawan Normal, 7 Tenaga Ahli, 8 Mekanik Magang, 9 Operator Magang)
 *   LL  lokasi penerimaan   (e.g. 15 Murung Raya, 03 Rantau, 01/11 Head Office)
 *   YY  tahun masuk         (2 digits)
 *   NNN nomor urut
 * Jenis karyawan, tempat penerimaan and status asal are read from it.
 */
class EmployeeNumber
{
    public const LENGTH = 8;

    /** @var array<string, string>|null kode => nama */
    private static ?array $type = null;

    /** @var array<string, array{nama: string, work_location_id: ?int}>|null */
    private static ?array $location = null;

    /** Called when a code is added, changed or removed. */
    public static function flush(): void
    {
        self::$type = null;
        self::$location = null;
    }

    /** @return array<string, string> */
    public static function categoryMap(): array
    {
        return self::$type ??= EmployeeCategory::orderBy('code')->pluck('name', 'code')->all();
    }

    /** @return array<string, array{nama: string, work_location_id: ?int}> */
    public static function pointOfHireMap(): array
    {
        return self::$location ??= PointOfHire::orderBy('code')->get()
            ->mapWithKeys(fn (PointOfHire $l) => [$l->code => ['name' => $l->name, 'work_location_id' => $l->work_location_id]])
            ->all();
    }

    /**
     * Why this NRP does not follow the pattern, or null when it does. Used to
     * reject new employees; the message names the offending digits.
     */
    public static function error(string $employee_number): ?string
    {
        $employee_number = trim($employee_number);

        if (! preg_match('/^\d{'.self::LENGTH.'}$/', $employee_number)) {
            return 'NRP harus '.self::LENGTH.' digit angka: jenis karyawan (1) + lokasi penerimaan (2) + tahun masuk (2) + nomor urut (3), contoh 11524001.';
        }

        $type = substr($employee_number, 0, 1);
        if (! isset(self::categoryMap()[$type])) {
            $codes = implode(', ', array_keys(self::categoryMap()));

            return "Digit ke-1 NRP \"{$employee_number}\" adalah \"{$type}\", bukan kode jenis karyawan yang terdaftar ({$codes}). Periksa NRP atau tambahkan kodenya di Pengaturan Core HR.";
        }

        $location = substr($employee_number, 1, 2);
        if (! isset(self::pointOfHireMap()[$location])) {
            $codes = implode(', ', array_keys(self::pointOfHireMap()));

            return "Digit ke-2–3 NRP \"{$employee_number}\" adalah \"{$location}\", bukan kode lokasi penerimaan yang terdaftar ({$codes}). Periksa NRP atau tambahkan kodenya di Pengaturan Core HR.";
        }

        return null;
    }

    /**
     * Digits 4–5 should be the year of Tanggal Masuk. Only a warning: an NRP
     * issued late in December for a January start is legitimate.
     */
    public static function yearWarning(string $employee_number, ?CarbonInterface $hireDate): ?string
    {
        if (! $hireDate || ! preg_match('/^\d{8}$/', $employee_number)) {
            return null;
        }

        $yy = substr($employee_number, 3, 2);

        return $yy === $hireDate->format('y')
            ? null
            : "Digit ke-4–5 NRP {$employee_number} (\"{$yy}\") tidak sama dengan tahun Tanggal Masuk ({$hireDate->year}). Pastikan NRP atau tanggal masuknya benar.";
    }

    /** "Lokal" when hired at the location the employee works at, "Kiriman" otherwise; null when unknown. */
    public static function originStatus(string $employee_number, ?int $workLocationId): ?string
    {
        $location = self::pointOfHireMap()[substr($employee_number, 1, 2)] ?? null;

        if (! $location || ! $location['work_location_id'] || ! $workLocationId) {
            return null;
        }

        return $location['work_location_id'] === $workLocationId ? 'Lokal' : 'Kiriman';
    }

    /**
     * Values read from the NRP, for display and the dashboard.
     *
     * @return array{jenis_karyawan: ?string, tempat_penerimaan: ?string, status_asal: ?string}
     */
    public static function derived(Employee $employee): array
    {
        return [
            'employee_category' => self::categoryMap()[substr($employee->employee_number, 0, 1)] ?? null,
            'hire_location' => self::pointOfHireMap()[substr($employee->employee_number, 1, 2)]['name'] ?? null,
            'origin_status' => self::originStatus($employee->employee_number, $employee->work_location_id),
        ];
    }

    /** Next free NRP for a jenis, lokasi and year, or null once all 999 numbers are used. */
    public static function next(string $type, string $location, int $year): ?string
    {
        $prefix = $type.$location.sprintf('%02d', $year % 100);
        $max = (int) Employee::withoutGlobalScope('access')->where('employee_number', 'like', "{$prefix}___")->max('employee_number');
        $sequence = $max ? (int) substr((string) $max, -3) + 1 : 1;

        return $sequence > 999 ? null : $prefix.sprintf('%03d', $sequence);
    }

    /**
     * Highest nomor urut used per 5-digit prefix (J LL YY), so a form can suggest
     * the next NRP without a round trip.
     *
     * @return array<string, int>
     */
    public static function maxSequencePerPrefix(): array
    {
        return Employee::withoutGlobalScope('access')->pluck('employee_number')
            ->filter(fn (string $employee_number) => preg_match('/^\d{8}$/', $employee_number))
            ->groupBy(fn (string $employee_number) => substr($employee_number, 0, 5))
            ->map(fn ($group) => $group->map(fn (string $employee_number) => (int) substr($employee_number, 5))->max())
            ->all();
    }
}
