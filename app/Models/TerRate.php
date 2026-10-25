<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One bracket of the monthly TER (PP 58/2023): category A/B/C, gross from–to, rate in %. */
class TerRate extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['lower_bound' => 'integer', 'upper_bound' => 'integer', 'rate' => 'float'];
    }
}
