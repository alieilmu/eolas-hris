<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One actual day of an employee. */
#[Fillable(['employee_id', 'date', 'shift_code_id', 'source', 'leave_travel_request_id'])]
class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';

    public const SUMBER = ['manual', 'schedule', 'import', 'leave_travel'];

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

    public function leave_travel(): BelongsTo
    {
        return $this->belongsTo(LeaveTravelRequest::class, 'leave_travel_request_id');
    }
}
