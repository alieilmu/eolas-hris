<?php

namespace App\Models;

use App\Support\Compensation\BenefitEntitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Plafon Kacamata per family member: bingkai once in 2 years, lensa once a year. */
#[Fillable(['layer', 'grade_ids', 'job_level_ids', 'position_label_head_office', 'position_label_site', 'frame', 'lens', 'sort_order'])]
class OpticalBenefitLimit extends Model
{
    /** Years a frame claim covers (the claim year and the one after). */
    public const BINGKAI_TAHUN = 2;

    protected function casts(): array
    {
        return ['grade_ids' => 'array', 'job_level_ids' => 'array', 'frame' => 'integer', 'lens' => 'integer'];
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => BenefitEntitlement::flush());
        static::deleted(fn () => BenefitEntitlement::flush());
    }
}
