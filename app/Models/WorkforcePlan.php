<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The Man Power Planning of one work location for one period (a quarter or a semester). */
#[Fillable(['work_location_id', 'type', 'year', 'period_number', 'start_date', 'end_date', 'source_plan_id'])]
class WorkforcePlan extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'immutable_date:Y-m-d', 'end_date' => 'immutable_date:Y-m-d', 'year' => 'integer', 'period_number' => 'integer'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkforcePlanItem::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkforcePlanRevision::class)->latest('id');
    }

    /** "Q2 2027", "S1 2027". */
    public function label(): string
    {
        return ($this->type === 'semiannual' ? 'S' : 'Q')."{$this->period_number} {$this->year}";
    }

    /** "2027-Q2", as in the address. */
    public function code(): string
    {
        return "{$this->year}-".($this->type === 'semiannual' ? 'S' : 'Q').$this->period_number;
    }
}
