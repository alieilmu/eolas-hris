<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'order'])]
class Bod extends Model
{
    protected $table = 'bods';

    public function divisis(): HasMany
    {
        return $this->hasMany(Divisi::class)->orderBy('order');
    }

    /** Positions attached to the board itself (the directors). */
    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class)->orderBy('order');
    }
}
