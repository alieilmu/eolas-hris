<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuti lapangan of a work location (or one sub location): leave days + travel out + travel back. */
#[Fillable(['work_location_id', 'sub_location_id', 'leave_days', 'outbound_travel_days', 'return_travel_days'])]
class FieldLeaveEntitlement extends Model
{
    protected function casts(): array
    {
        return ['leave_days' => 'integer', 'outbound_travel_days' => 'integer', 'return_travel_days' => 'integer'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(SubLocation::class);
    }

    public function total(): int
    {
        return $this->leave_days + $this->outbound_travel_days + $this->return_travel_days;
    }
}
