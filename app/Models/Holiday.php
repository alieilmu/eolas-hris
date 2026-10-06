<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A public holiday, marked in the Roster and Absensi tables. */
#[Fillable(['tanggal', 'nama'])]
class Holiday extends Model
{
    protected function casts(): array
    {
        return [
            'tanggal' => 'immutable_date:Y-m-d',
        ];
    }
}
