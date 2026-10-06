<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['divisi_id', 'name', 'order'])]
class Departemen extends Model
{
    protected $table = 'departemens';

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    /** Positions attached to the departemen itself (not to one of its sections). */
    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class)->orderBy('order');
    }
}
