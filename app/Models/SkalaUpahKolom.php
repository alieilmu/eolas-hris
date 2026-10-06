<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A column of the Struktur Skala Upah: a year, for every site or for one work location. */
#[Fillable(['tahun', 'work_location_id', 'urutan'])]
class SkalaUpahKolom extends Model
{
    protected $table = 'skala_upah_kolom';

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** "Tahun 2026 - Site RMTU" */
    public function label(): string
    {
        return "Tahun {$this->tahun}".($this->workLocation ? " - Site {$this->workLocation->name}" : '');
    }
}
