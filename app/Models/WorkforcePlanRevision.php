<?php

namespace App\Models;

use App\Support\Notifications\RequestNotifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a plan: waiting for an approver (diajukan), or kept as history once
 * decided — saved straight away when an approver made it ($langsung).
 */
#[Fillable(['workforce_plan_id', 'status', 'changes', 'is_direct', 'notes', 'verification_notes', 'created_by', 'verified_by', 'verified_at'])]
class WorkforcePlanRevision extends Model
{
    public const STATUS = ['pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'is_direct' => 'boolean', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // The approvers' notification is done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'pending' && RequestNotifier::close("workforce_planning:{$m->id}"));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlan::class, 'workforce_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
