<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'type', 'sort_order', 'work_schedule_scheme', 'daily_normal_hours', 'jkk_rate', 'minimum_wage', 'mobile_attendance'])]
class WorkLocation extends Model
{
    public const TYPE_HEAD_OFFICE = 'head_office';

    public const TYPE_SITE = 'site';

    public const TYPE_LABELS = [self::TYPE_HEAD_OFFICE => 'Head Office', self::TYPE_SITE => 'Site'];

    /** Working-time schemes: the basis of normal hours and overtime (Attendance → Lembur). */
    public const SKEMA_KERJA = [
        'five_day' => '5 hari kerja · 8 jam/hari (PP 35/2021)',
        'six_day' => '6 hari kerja · 7 jam/hari (PP 35/2021)',
        'mining_roster' => 'Waktu kerja tertentu ESDM · roster (Kepmenakertrans 234/2003)',
    ];

    /** Clocking in from the phone: not used, only inside a geofence, or outside taken but marked. */
    public const MOBILE_ATTENDANCE = [
        'off' => 'Tidak dipakai',
        'reject' => 'Hanya di dalam area (di luar ditolak)',
        'flag' => 'Di luar area diterima, ditandai',
    ];

    protected function casts(): array
    {
        return ['daily_normal_hours' => 'float', 'jkk_rate' => 'float', 'minimum_wage' => 'integer'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Where its employees may clock in from the phone. */
    public function geofences(): HasMany
    {
        return $this->hasMany(WorkLocationGeofence::class)->orderBy('id');
    }

    public function subLocations(): HasMany
    {
        return $this->hasMany(SubLocation::class)->orderBy('sort_order')->orderBy('name');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /** NRP location codes that stand for this place (decides Lokal / Kiriman). */
    public function pointsOfHire(): HasMany
    {
        return $this->hasMany(PointOfHire::class);
    }

    public function isHeadOffice(): bool
    {
        return $this->type === self::TYPE_HEAD_OFFICE;
    }

    /** How the place appears in a title: its short code (RMTU) or else its name. */
    public function titleName(): string
    {
        return $this->code ?: $this->name;
    }
}
