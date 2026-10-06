<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One planned day of an employee. */
#[Fillable(['karyawan_id', 'tanggal', 'shift_code_id'])]
class Roster extends Model
{
    protected function casts(): array
    {
        return [
            'tanggal' => 'immutable_date:Y-m-d',
        ];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function shiftCode(): BelongsTo
    {
        return $this->belongsTo(ShiftCode::class);
    }
}
