<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The approval flow of one kind of pengajuan, one version of it (App\Support\Workflow\Workflow). */
#[Fillable(['type', 'name', 'version', 'is_active', 'created_by'])]
class WorkflowDefinition extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sequence');
    }
}
