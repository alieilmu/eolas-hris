<?php

namespace App\Models;

use App\Support\Comben\Entitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Plafon Pengobatan (rawat jalan) per year for one family: lajang or keluarga. */
#[Fillable(['layer', 'golongan_ids', 'sub_group_ids', 'jabatan_ho', 'jabatan_site', 'lajang', 'keluarga', 'urutan'])]
class PlafonPengobatan extends Model
{
    protected function casts(): array
    {
        return ['golongan_ids' => 'array', 'sub_group_ids' => 'array', 'lajang' => 'integer', 'keluarga' => 'integer'];
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => Entitlement::flush());
        static::deleted(fn () => Entitlement::flush());
    }
}
