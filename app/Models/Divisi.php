<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['bod_id', 'name', 'order'])]
class Divisi extends Model
{
    protected $table = 'divisis';

    public function bod(): BelongsTo
    {
        return $this->belongsTo(Bod::class);
    }

    public function departemens(): HasMany
    {
        return $this->hasMany(Departemen::class)->orderBy('order');
    }

    /** Positions attached to the divisi itself (e.g. its head). */
    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class)->orderBy('order');
    }
}
