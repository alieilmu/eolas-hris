<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeNumberFormat;
use App\Models\PointOfHire;
use Carbon\CarbonInterface;

/**
 * The Nomor Induk (NRP), built as the company set it up (App\Models\EmployeeNumberFormat).
 * The default is J LL YY NNN (8 digits):
 *   J   jenis karyawan      (1 Karyawan Normal, 7 Tenaga Ahli, 8 Mekanik Magang, 9 Operator Magang)
 *   LL  lokasi penerimaan   (e.g. 15 Murung Raya, 03 Rantau, 01/11 Head Office)
 *   YY  tahun masuk         (2 digits)
 *   NNN nomor urut
 * Another company may use e.g. "TY" + jenis (2 letters) + YYYY + 4-digit nomor urut, or no
 * pattern at all. Jenis karyawan, tempat penerimaan and status asal are read from the
 * segments the format has; a free format only asks for a unique number.
 */
class EmployeeNumber
{
    /** @var array<string, string>|null kode => nama */
    private static ?array $type = null;

    /** @var array<string, array{name: string, work_location_id: ?int}>|null */
    private static ?array $location = null;

    /** @var array{mode: string, segments: list<array{type: string, length?: int, value?: string}>, max_length: int}|null */
    private static ?array $format = null;

    /** Called when a code or the format is added, changed or removed. */
    public static function flush(): void
    {
        self::$type = null;
        self::$location = null;
        self::$format = null;
    }

    /** @return array{mode: string, segments: list<array{type: string, length?: int, value?: string}>, max_length: int} */
    public static function format(): array
    {
        if (self::$format === null) {
            $f = EmployeeNumberFormat::current();
            self::$format = ['mode' => $f->mode, 'segments' => $f->segments, 'max_length' => $f->max_length];
        }

        return self::$format;
    }

    public static function isFree(): bool
    {
        return self::format()['mode'] === 'free';
    }

    /** Length of a segment type in the format (codes are entered at this length), or null when it has none. */
    public static function segmentLength(string $type): ?int
    {
        foreach (self::isFree() ? [] : self::format()['segments'] as $s) {
            if ($s['type'] === $type) {
                return $type === 'text' ? strlen($s['value'] ?? '') : (int) $s['length'];
            }
        }

        return null;
    }

    public static function length(): ?int
    {
        return self::isFree() ? null : array_sum(array_map(fn (array $s) => $s['type'] === 'text' ? strlen($s['value'] ?? '') : (int) $s['length'], self::format()['segments']));
    }

    /** As stored: trimmed; upper case when the format has a pattern (codes may be letters). */
    public static function normalize(?string $employeeNumber): string
    {
        $value = trim((string) $employeeNumber);

        return self::isFree() ? $value : strtoupper($value);
    }

    /** @return array<string, string> */
    public static function categoryMap(): array
    {
        return self::$type ??= EmployeeCategory::orderBy('code')->pluck('name', 'code')->mapWithKeys(fn ($n, $c) => [(string) $c => $n])->all();
    }

    /** @return array<string, array{name: string, work_location_id: ?int}> */
    public static function pointOfHireMap(): array
    {
        return self::$location ??= PointOfHire::orderBy('code')->get()
            ->mapWithKeys(fn (PointOfHire $l) => [(string) $l->code => ['name' => $l->name, 'work_location_id' => $l->work_location_id]])
            ->all();
    }

    /**
     * The segments of an NRP, by type, plus "prefix" (everything before the nomor urut);
     * null when it does not fit the format (length or fixed text). Free format: null.
     *
     * @return array{category?: string, point_of_hire?: string, year?: string, sequence?: string, prefix: string}|null
     */
    public static function parse(string $employeeNumber): ?array
    {
        if (self::isFree()) {
            return null;
        }
        $value = strtoupper(trim($employeeNumber));
        if (strlen($value) !== self::length()) {
            return null;
        }
        $out = ['prefix' => ''];
        $at = 0;
        foreach (self::format()['segments'] as $s) {
            $len = $s['type'] === 'text' ? strlen($s['value'] ?? '') : (int) $s['length'];
            $part = substr($value, $at, $len);
            if ($s['type'] === 'text' && $part !== strtoupper($s['value'] ?? '')) {
                return null;
            }
            if ($s['type'] === 'sequence') {
                $out['prefix'] = substr($value, 0, $at);
            }
            if ($s['type'] !== 'text') {
                $out[$s['type']] = $part;
            }
            $at += $len;
        }

        return $out;
    }

    /** "TY + jenis karyawan (2) + tahun masuk (4) + nomor urut (4)", for messages and pages. */
    public static function describe(): string
    {
        if (self::isFree()) {
            return 'bebas, unik, maksimal '.self::format()['max_length'].' karakter';
        }

        return implode(' + ', array_map(fn (array $s) => $s['type'] === 'text'
            ? '"'.$s['value'].'"'
            : mb_strtolower(EmployeeNumberFormat::SEGMENT_TYPES[$s['type']]).' ('.$s['length'].')', self::format()['segments']));
    }

    /** An example NRP of the format, with the first registered codes. */
    public static function example(int $year = 2024): string
    {
        if (self::isFree()) {
            return 'EMP-0001';
        }

        return implode('', array_map(fn (array $s) => match ($s['type']) {
            'text' => $s['value'],
            'category' => str_pad((string) (array_key_first(self::categoryMap()) ?? '1'), $s['length'], '0', STR_PAD_LEFT),
            'point_of_hire' => str_pad((string) (array_key_first(self::pointOfHireMap()) ?? '1'), $s['length'], '0', STR_PAD_LEFT),
            'year' => substr((string) $year, -$s['length']),
            'sequence' => str_pad('1', $s['length'], '0', STR_PAD_LEFT),
        }, self::format()['segments']));
    }

    /**
     * Why this NRP does not follow the format, or null when it does. Used to reject new
     * employees; the message names the offending characters.
     */
    public static function error(string $employeeNumber): ?string
    {
        $value = self::normalize($employeeNumber);
        if (self::isFree()) {
            $max = self::format()['max_length'];

            return match (true) {
                $value === '' => 'NRP wajib diisi.',
                mb_strlen($value) > $max => "NRP maksimal {$max} karakter.",
                ! preg_match('#^[A-Za-z0-9./_-]+$#', $value) => 'NRP hanya boleh huruf, angka, dan . / _ - tanpa spasi.',
                default => null,
            };
        }

        $length = self::length();
        $describe = self::describe().', contoh '.self::example();
        if (strlen($value) !== $length) {
            return "NRP harus {$length} karakter: {$describe}.";
        }
        $at = 0;
        foreach (self::format()['segments'] as $s) {
            $len = $s['type'] === 'text' ? strlen($s['value'] ?? '') : (int) $s['length'];
            $part = substr($value, $at, $len);
            $where = $len === 1 ? 'Karakter ke-'.($at + 1) : 'Karakter ke-'.($at + 1).'–'.($at + $len);
            $error = match ($s['type']) {
                'text' => $part === strtoupper($s['value'] ?? '') ? null : "{$where} NRP harus \"{$s['value']}\" ({$describe}).",
                'category' => isset(self::categoryMap()[$part]) ? null
                    : "{$where} NRP \"{$value}\" adalah \"{$part}\", bukan kode jenis karyawan yang terdaftar (".implode(', ', array_keys(self::categoryMap())).'). Periksa NRP atau tambahkan kodenya di Pengaturan Core HR.',
                'point_of_hire' => isset(self::pointOfHireMap()[$part]) ? null
                    : "{$where} NRP \"{$value}\" adalah \"{$part}\", bukan kode lokasi penerimaan yang terdaftar (".implode(', ', array_keys(self::pointOfHireMap())).'). Periksa NRP atau tambahkan kodenya di Pengaturan Core HR.',
                'year', 'sequence' => ctype_digit($part) ? null : "{$where} NRP (".mb_strtolower(EmployeeNumberFormat::SEGMENT_TYPES[$s['type']]).') harus angka.',
            };
            if ($error) {
                return $error;
            }
            $at += $len;
        }

        return null;
    }

    /**
     * The year segment should be the year of Tanggal Masuk. Only a warning: an NRP
     * issued late in December for a January start is legitimate.
     */
    public static function yearWarning(string $employeeNumber, ?CarbonInterface $hireDate): ?string
    {
        $year = self::parse($employeeNumber)['year'] ?? null;
        if (! $hireDate || $year === null || ! ctype_digit($year)) {
            return null;
        }

        return $year === substr((string) $hireDate->year, -strlen($year))
            ? null
            : "Tahun masuk di NRP {$employeeNumber} (\"{$year}\") tidak sama dengan tahun Tanggal Masuk ({$hireDate->year}). Pastikan NRP atau tanggal masuknya benar.";
    }

    /** "Lokal" when hired at the location the employee works at, "Kiriman" otherwise; null when unknown. */
    public static function originStatus(string $employeeNumber, ?int $workLocationId): ?string
    {
        $code = self::parse($employeeNumber)['point_of_hire'] ?? null;
        $location = $code === null ? null : (self::pointOfHireMap()[$code] ?? null);

        if (! $location || ! $location['work_location_id'] || ! $workLocationId) {
            return null;
        }

        return $location['work_location_id'] === $workLocationId ? 'Lokal' : 'Kiriman';
    }

    /**
     * Values read from the NRP, for display and the dashboard.
     *
     * @return array{employee_category: ?string, hire_location: ?string, origin_status: ?string}
     */
    public static function derived(Employee $employee): array
    {
        $parts = self::parse($employee->employee_number) ?? [];

        return [
            'employee_category' => isset($parts['category']) ? (self::categoryMap()[$parts['category']] ?? null) : null,
            'hire_location' => isset($parts['point_of_hire']) ? (self::pointOfHireMap()[$parts['point_of_hire']]['name'] ?? null) : null,
            'origin_status' => self::originStatus($employee->employee_number, $employee->work_location_id),
        ];
    }

    /** The NRP before its nomor urut for these codes and year; null in a free format. */
    public static function prefix(?string $type, ?string $location, int $year): ?string
    {
        if (self::isFree()) {
            return null;
        }
        $prefix = '';
        foreach (self::format()['segments'] as $s) {
            $prefix .= match ($s['type']) {
                'text' => strtoupper($s['value']),
                'category' => strtoupper((string) $type),
                'point_of_hire' => strtoupper((string) $location),
                'year' => substr(sprintf('%04d', $year), -$s['length']),
                'sequence' => '',
            };
        }

        return $prefix;
    }

    /** Next free NRP for a jenis, lokasi and year, or null once the nomor urut is full (or the format is free). */
    public static function next(?string $type, ?string $location, int $year): ?string
    {
        $length = self::segmentLength('sequence');
        $prefix = self::prefix($type, $location, $year);
        if ($length === null || $prefix === null) {
            return null;
        }
        $sequence = (self::maxSequencePerPrefix()[$prefix] ?? 0) + 1;

        return $sequence >= 10 ** $length ? null : $prefix.str_pad((string) $sequence, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Highest nomor urut used per prefix (everything before the nomor urut), so a form can
     * suggest the next NRP without a round trip.
     *
     * @return array<string, int>
     */
    public static function maxSequencePerPrefix(): array
    {
        $max = [];
        foreach (Employee::withoutGlobalScope('access')->pluck('employee_number') as $employeeNumber) {
            $parts = self::parse((string) $employeeNumber);
            if ($parts && isset($parts['sequence']) && ctype_digit($parts['sequence'])) {
                $max[$parts['prefix']] = max($max[$parts['prefix']] ?? 0, (int) $parts['sequence']);
            }
        }

        return $max;
    }
}
