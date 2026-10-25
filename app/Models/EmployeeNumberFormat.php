<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How the company's Nomor Induk (NRP) is built (one row, Pengaturan Core HR → Format Nomor Induk).
 *  - pattern: fixed-width segments in order — text (e.g. "TY"), category (jenis karyawan code),
 *    point_of_hire (lokasi penerimaan code), year (of Tanggal Masuk, 2 or 4 digits) and, last,
 *    sequence (nomor urut); codes may hold letters;
 *  - free: any unique text up to max_length, nothing read from it.
 */
#[Fillable(['mode', 'segments', 'max_length'])]
class EmployeeNumberFormat extends Model
{
    public const SEGMENT_TYPES = [
        'text' => 'Teks tetap',
        'category' => 'Jenis karyawan',
        'point_of_hire' => 'Lokasi penerimaan',
        'year' => 'Tahun masuk',
        'sequence' => 'Nomor urut',
    ];

    public const DEFAULT_SEGMENTS = [
        ['type' => 'category', 'length' => 1],
        ['type' => 'point_of_hire', 'length' => 2],
        ['type' => 'year', 'length' => 2],
        ['type' => 'sequence', 'length' => 3],
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\Employees\EmployeeNumber::flush());
    }

    protected function casts(): array
    {
        return ['segments' => 'array', 'max_length' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create(['mode' => 'pattern', 'segments' => self::DEFAULT_SEGMENTS]);
    }
}
