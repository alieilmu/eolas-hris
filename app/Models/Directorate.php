<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class Directorate extends Model
{
    protected $table = 'directorates';

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class)->orderBy('sort_order');
    }

    /** Positions attached to the board itself (the directors). */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class)->orderBy('sort_order');
    }
}
