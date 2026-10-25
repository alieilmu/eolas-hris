<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use App\Support\Notifications\RequestNotifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The choice for one Cuti Besar entitlement: diambil (the days + 1x gapok) or
 * diuangkan (2x gapok). From the portal it waits for HR (diajukan).
 */
#[Fillable(['employee_id', 'sequence_no', 'decision', 'status', 'source', 'notes', 'verified_by', 'verified_at'])]
class LongServiceLeave extends Model
{
    public const KEPUTUSAN = ['taken' => 'Diambil', 'cashed_out' => 'Diuangkan'];

    protected function casts(): array
    {
        return ['sequence_no' => 'integer', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Its notifications for the admins are done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'pending' && RequestNotifier::close("long-service-leave:{$m->id}"));
        static::deleted(fn (self $m) => RequestNotifier::close("long-service-leave:{$m->id}"));
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
