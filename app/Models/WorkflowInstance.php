<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One pengajuan going through its flow: the step it is at and who may decide it there
 * (current_approvers, user ids worked out when the step began), or how it ended.
 */
#[Fillable(['workflow_definition_id', 'subject_type', 'subject_id', 'employee_id', 'current_step', 'current_approvers', 'status', 'started_by', 'completed_at'])]
class WorkflowInstance extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [self::PENDING => 'Menunggu', self::APPROVED => 'Disetujui', self::REJECTED => 'Ditolak', self::CANCELLED => 'Dibatalkan'];

    protected function casts(): array
    {
        return ['current_approvers' => 'array', 'current_step' => 'integer', 'completed_at' => 'datetime'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function step(): ?WorkflowStep
    {
        return $this->current_step === null ? null : $this->definition->steps->firstWhere('sequence', $this->current_step);
    }
}
