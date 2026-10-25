<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line of the wage scale: a golongan (and step), with its wage per column. */
#[Fillable(['grade_id', 'step', 'sort_order'])]
class SalaryScale extends Model
{
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function value(): HasMany
    {
        return $this->hasMany(SalaryScaleAmount::class);
    }
}
