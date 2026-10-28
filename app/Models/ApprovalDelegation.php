<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approver's substitute in Workflow: the delegate decides in the approver's name while it
 * applies — for a range of dates, or on every day the approver is on approved leave.
 */
#[Fillable(['user_id', 'delegate_id', 'mode', 'starts_on', 'ends_on', 'notes', 'is_active'])]
class ApprovalDelegation extends Model
{
    public const RANGE = 'range';

    public const ON_LEAVE = 'on_leave';

    public const MODES = [self::RANGE => 'Rentang tanggal', self::ON_LEAVE => 'Setiap saya cuti (yang disetujui)'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date:Y-m-d', 'ends_on' => 'immutable_date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** "12.11 – 15.11.2026" or "setiap cuti". */
    public function period(): string
    {
        if ($this->mode === self::ON_LEAVE) {
            return 'setiap cuti';
        }

        return $this->starts_on->format('d.m').' – '.$this->ends_on->format('d.m.Y');
    }

    public function expired(?CarbonImmutable $today = null): bool
    {
        return $this->mode === self::RANGE && $this->ends_on->lt($today ?? CarbonImmutable::today());
    }
}
