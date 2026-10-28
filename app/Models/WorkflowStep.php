<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a flow: who approves it (Approvers::TYPES), when it applies (Conditions) and
 * whether one approver is enough ("any") or all of them must agree ("all").
 * approver_value: the levels to climb (superior), a position id (position) or a menu (menu).
 */
#[Fillable(['workflow_definition_id', 'sequence', 'name', 'approver_type', 'approver_value', 'conditions', 'mode', 'due_days'])]
class WorkflowStep extends Model
{
    public const ANY = 'any';

    public const ALL = 'all';

    protected $attributes = ['mode' => self::ANY];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'sequence' => 'integer', 'due_days' => 'integer'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
