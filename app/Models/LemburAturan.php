<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** How overtime is rounded and bounded (one row, Master Data Attendance → Waktu Kerja & Lembur). */
#[Fillable(['pembulatan_menit', 'minimal_menit', 'hitung_sebelum_jadwal', 'batas_harian_jam', 'batas_mingguan_jam'])]
class LemburAturan extends Model
{
    protected function casts(): array
    {
        return ['hitung_sebelum_jadwal' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([]);
    }
}
