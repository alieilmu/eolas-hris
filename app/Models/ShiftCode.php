<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code used in Roster and Absensi (D, N, OFF, ...). Its DWS is what an export
 * for payroll carries; its jenis says what kind of day it is.
 */
#[Fillable(['kode', 'dws', 'keterangan', 'jenis', 'order', 'jam_masuk', 'jam_keluar', 'istirahat_mulai', 'istirahat_selesai'])]
class ShiftCode extends Model
{
    /** What kind of day a code means; drives % Kehadiran and Man Onsite. */
    public const JENIS = [
        'hadir' => 'Hadir (kerja di lokasi)',
        'dinas' => 'Dinas luar',
        'off' => 'Off',
        'cuti' => 'Cuti',
        'sakit' => 'Sakit',
        'izin' => 'Izin',
        'mangkir' => 'Mangkir',
    ];

    /** "07:00", without seconds, for the pages. */
    public static function hhmm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    /** Has working hours set (a work shift with a schedule). */
    public function terjadwal(): bool
    {
        return $this->jam_masuk !== null && $this->jam_keluar !== null;
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }

    public function absensis(): HasMany
    {
        return $this->hasMany(Absensi::class);
    }

    /** The code a Trip & Leave entry writes into Absensi: the first one of that jenis. */
    public static function forJenis(string $jenis): ?self
    {
        return self::where('jenis', $jenis)->orderBy('order')->orderBy('id')->first();
    }
}
