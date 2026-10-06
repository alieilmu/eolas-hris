<?php

namespace App\Models;

use App\Support\Comben\Entitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A golongan (1A, D1J, G2S, 3A, ...) within its group; employees hold one by its code. */
#[Fillable(['grup', 'kode', 'urutan'])]
class Golongan extends Model
{
    /** The groups as first given; more can be typed in Master Data Comben. */
    public const GRUP = ['NONOM', 'OPERATOR', 'MEKANIK', 'WELDER', 'STAFF'];

    public function skalaUpahs(): HasMany
    {
        return $this->hasMany(SkalaUpah::class)->orderBy('urutan');
    }

    /** @return list<string> every code, in master order */
    public static function codes(): array
    {
        return self::orderBy('urutan')->orderBy('id')->pluck('kode')->all();
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => Entitlement::flush());
        static::deleted(fn () => Entitlement::flush());
    }
}
