<?php

namespace App\Models;

use App\Support\Compensation\BenefitEntitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A golongan (1A, D1J, G2S, 3A, ...) within its group; employees hold one by its code. */
#[Fillable(['grade_group', 'code', 'sort_order'])]
class Grade extends Model
{
    /** The groups as first given; more can be typed in Pengaturan Kompensasi. */
    public const GRUP = ['NONOM', 'OPERATOR', 'MEKANIK', 'WELDER', 'STAFF'];

    public function salaryScales(): HasMany
    {
        return $this->hasMany(SalaryScale::class)->orderBy('sort_order');
    }

    /** @return list<string> every code, in master order */
    public static function codes(): array
    {
        return self::orderBy('sort_order')->orderBy('id')->pluck('code')->all();
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => BenefitEntitlement::flush());
        static::deleted(fn () => BenefitEntitlement::flush());
    }
}
