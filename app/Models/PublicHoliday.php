<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A public holiday, marked in the Roster and Absensi tables. */
#[Fillable(['date', 'name'])]
class PublicHoliday extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
        ];
    }
}
