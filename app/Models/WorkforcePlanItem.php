<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One position in a plan: how many it needs, and who is expected to leave it this period. */
#[Fillable([
    'workforce_plan_id', 'position_id', 'plan', 'promotions', 'rotations', 'demotions', 'termination', 'retirements', 'sick',
    'movement_month', 'fulfilment_month', 'source', 'grade_id', 'description',
])]
class WorkforcePlanItem extends Model
{
    /** The fields HR edits (a change by an account without approval becomes a revision). */
    public const FIELDS = ['plan', 'promotions', 'rotations', 'demotions', 'termination', 'retirements', 'sick', 'movement_month', 'fulfilment_month', 'source', 'grade_id', 'description'];

    public const SUMBER = ['internal' => 'Internal (LA)', 'external' => 'Eksternal (ODTA)'];

    protected function casts(): array
    {
        return [
            'plan' => 'integer', 'promotions' => 'integer', 'rotations' => 'integer', 'demotions' => 'integer', 'termination' => 'integer',
            'retirements' => 'integer', 'sick' => 'integer', 'movement_month' => 'integer', 'fulfilment_month' => 'integer', 'grade_id' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlan::class, 'workforce_plan_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
