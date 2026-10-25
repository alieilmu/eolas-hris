<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One planned day of an employee. */
#[Fillable(['employee_id', 'date', 'shift_code_id'])]
class ShiftSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftCode(): BelongsTo
    {
        return $this->belongsTo(ShiftCode::class);
    }
}
