<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a flow: who approves it (Approvers::TYPES) and, from week 2, when it applies.
 * approver_value: the levels to climb (superior), a position id (position) or a menu (menu).
 */
#[Fillable(['workflow_definition_id', 'sequence', 'name', 'approver_type', 'approver_value', 'conditions'])]
class WorkflowStep extends Model
{
    protected function casts(): array
    {
        return ['conditions' => 'array', 'sequence' => 'integer'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
