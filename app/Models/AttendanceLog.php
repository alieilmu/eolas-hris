<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clock in / clock out from a machine, an imported file, or a correction by HR. */
#[Fillable(['attendance_device_id', 'pin', 'employee_id', 'punched_at', 'status_code', 'verify', 'source', 'notes', 'created_by'])]
class AttendanceLog extends Model
{
    public const SUMBER = ['device' => 'Mesin', 'import' => 'Import', 'manual' => 'Koreksi HR'];

    protected function casts(): array
    {
        return ['punched_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
