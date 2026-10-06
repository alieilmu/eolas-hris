<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line of the wage scale: a golongan (and step), with its wage per column. */
#[Fillable(['golongan_id', 'step', 'urutan'])]
class SkalaUpah extends Model
{
    public function golongan(): BelongsTo
    {
        return $this->belongsTo(Golongan::class);
    }

    public function nilai(): HasMany
    {
        return $this->hasMany(SkalaUpahNilai::class);
    }
}
