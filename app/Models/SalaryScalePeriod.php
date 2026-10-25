<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A column of the Struktur Skala Upah: a year, for every site or for one work location. */
#[Fillable(['year', 'work_location_id', 'sort_order'])]
class SalaryScalePeriod extends Model
{
    protected $table = 'salary_scale_periods';

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** "Tahun 2026 - Site RMTU" */
    public function label(): string
    {
        return "Tahun {$this->year}".($this->workLocation ? " - Site {$this->workLocation->name}" : '');
    }
}
