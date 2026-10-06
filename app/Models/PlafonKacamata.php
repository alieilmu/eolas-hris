<?php

namespace App\Models;

use App\Support\Comben\Entitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Plafon Kacamata per family member: bingkai once in 2 years, lensa once a year. */
#[Fillable(['layer', 'golongan_ids', 'sub_group_ids', 'jabatan_ho', 'jabatan_site', 'bingkai', 'lensa', 'urutan'])]
class PlafonKacamata extends Model
{
    /** Years a frame claim covers (the claim year and the one after). */
    public const BINGKAI_TAHUN = 2;

    protected function casts(): array
    {
        return ['golongan_ids' => 'array', 'sub_group_ids' => 'array', 'bingkai' => 'integer', 'lensa' => 'integer'];
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => Entitlement::flush());
        static::deleted(fn () => Entitlement::flush());
    }
}
