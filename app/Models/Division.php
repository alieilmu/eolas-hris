<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['directorate_id', 'name', 'sort_order'])]
class Division extends Model
{
    protected $table = 'divisions';

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(Directorate::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class)->orderBy('sort_order');
    }

    /** Positions attached to the divisi itself (e.g. its head). */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class)->orderBy('sort_order');
    }
}
