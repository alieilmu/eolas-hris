<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The leave rules (one row): annual quota and its life, and the Cuti Besar every N years. */
#[Fillable([
    'jatah_tahunan', 'masa_aktif_tahun', 'besar_interval_tahun', 'besar_hari', 'besar_gapok_diambil', 'besar_gapok_diuangkan', 'besar_batas_bulan',
])]
class CutiAturan extends Model
{
    protected function casts(): array
    {
        return [
            'jatah_tahunan' => 'integer',
            'masa_aktif_tahun' => 'integer',
            'besar_interval_tahun' => 'integer',
            'besar_hari' => 'integer',
            'besar_gapok_diambil' => 'float',
            'besar_gapok_diuangkan' => 'float',
            'besar_batas_bulan' => 'integer',
        ];
    }

    private static ?self $current = null;

    public static function current(): self
    {
        return self::$current ??= self::query()->first() ?? self::query()->create([]);
    }

    public static function forget(): void
    {
        self::$current = null;
    }
}
