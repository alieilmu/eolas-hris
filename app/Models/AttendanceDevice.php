<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An attendance machine (Attendance → Kehadiran → Mesin Absen). Machines push their logs
 * over ADMS (/iclock) identified by serial number; only "aktif" ones are taken in. A
 * machine that pushes before it was registered shows up as "baru" to be accepted.
 */
#[Fillable(['name', 'serial_number', 'brand', 'model', 'work_location_id', 'status', 'last_seen_at', 'last_ip', 'attlog_stamp', 'notes'])]
class AttendanceDevice extends Model
{
    public const STATUS = ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'new' => 'Baru terdeteksi'];

    /** Seen within this many minutes = online. */
    public const ONLINE_MENIT = 10;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function online(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_MENIT));
    }
}
