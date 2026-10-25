<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code used in Roster and Absensi (D, N, OFF, ...). Its DWS is what an export
 * for payroll carries; its jenis says what kind of day it is.
 */
#[Fillable(['code', 'daily_work_schedule', 'description', 'type', 'deducts_base_salary', 'sort_order', 'start_time', 'end_time', 'break_start', 'break_end'])]
class ShiftCode extends Model
{
    /** What kind of day a code means; drives % Kehadiran and Man Onsite. */
    public const JENIS = [
        'work' => 'Hadir (kerja di lokasi)',
        'business_trip' => 'Dinas luar',
        'off' => 'Off',
        'leave' => 'Cuti',
        'sick' => 'Sakit',
        'permission' => 'Izin',
        'absent' => 'Mangkir',
    ];

    protected function casts(): array
    {
        return ['deducts_base_salary' => 'boolean'];
    }

    /** "07:00", without seconds, for the pages. */
    public static function hhmm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    /** Has working hours set (a work shift with a schedule). */
    public function scheduled(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    public function shift_schedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    public function attendance_records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** The code a Cuti & Dinas entry writes into Absensi: the first one of that jenis. */
    public static function forType(string $type): ?self
    {
        return self::where('type', $type)->orderBy('sort_order')->orderBy('id')->first();
    }
}
