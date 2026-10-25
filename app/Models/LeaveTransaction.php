<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Leave taken outside Cuti & Dinas: imported from the old Excel monitoring, or typed by HR. */
#[Fillable(['employee_id', 'type', 'year', 'start_date', 'end_date', 'date_notes', 'days', 'source', 'notes', 'created_by'])]
class LeaveTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'year' => 'integer',
            'days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('access', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('employee');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
