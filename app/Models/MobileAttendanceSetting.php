<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Absen HP (one row, Pengaturan Waktu & Kehadiran → Absen HP): how long the selfies are kept. */
#[Fillable(['photo_retention_days'])]
class MobileAttendanceSetting extends Model
{
    public static function current(): self
    {
        // refresh(): the defaults are the table's (90 days), read back after creating the row.
        return self::query()->first() ?? self::query()->create([])->refresh();
    }
}
