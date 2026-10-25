<?php

namespace App\Models;

use App\Support\Compensation\BenefitEntitlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Plafon Pengobatan (rawat jalan) per year for one family: lajang or keluarga. */
#[Fillable(['layer', 'grade_ids', 'job_level_ids', 'position_label_head_office', 'position_label_site', 'single', 'family', 'sort_order'])]
class MedicalBenefitLimit extends Model
{
    protected function casts(): array
    {
        return ['grade_ids' => 'array', 'job_level_ids' => 'array', 'single' => 'integer', 'family' => 'integer'];
    }

    protected static function booted(): void
    {
        // Entitlement keeps these loaded; a change drops that copy.
        static::saved(fn () => BenefitEntitlement::flush());
        static::deleted(fn () => BenefitEntitlement::flush());
    }
}
