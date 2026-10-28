<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One decision on a pengajuan in its flow: who, at which step, what, with which note. */
#[Fillable(['workflow_instance_id', 'step', 'user_id', 'on_behalf_of', 'user_name', 'decision', 'notes', 'created_at'])]
class WorkflowAction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'step' => 'integer'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
