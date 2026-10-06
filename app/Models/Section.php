<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The organisation level under Departemen; most positions belong to one. */
#[Fillable(['departemen_id', 'name', 'order'])]
class Section extends Model
{
    public function departemen(): BelongsTo
    {
        return $this->belongsTo(Departemen::class);
    }

    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class)->orderBy('order');
    }
}
