<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** How overtime is rounded and bounded (one row, Pengaturan Waktu & Kehadiran → Waktu Kerja & Lembur). */
#[Fillable(['rounding_minutes', 'minimum_minutes', 'count_before_shift', 'daily_limit_hours', 'weekly_limit_hours'])]
class OvertimePolicy extends Model
{
    protected function casts(): array
    {
        return ['count_before_shift' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([]);
    }
}
