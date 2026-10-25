<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use App\Support\Notifications\RequestNotifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A Deklarasi Pengobatan / Kacamata form, booked to a month closing on the 8th. */
#[Fillable([
    'claim_number', 'employee_id', 'date', 'provider_name', 'receipt_count', 'accounting_month', 'benefit_year', 'description', 'created_by',
    'status', 'source', 'verified_by', 'verified_at', 'verification_notes',
])]
class BenefitClaim extends Model
{
    /** diajukan: from the employee portal, waiting for HR; disetujui counts; ditolak does not. */
    public const STATUS = ['pending', 'approved', 'rejected'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date:Y-m-d'];
    }

    protected static function booted(): void
    {
        // Its notifications for the admins are done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'pending' && RequestNotifier::close("claim:{$m->id}"));
        static::deleted(fn (self $m) => RequestNotifier::close("claim:{$m->id}"));
        // Only those of employees the account may see (Administrasi → Pengguna & Akses).
        static::addGlobalScope('access', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('employee');
            }
        });
        // Its attachments (and their files) go with it.
        static::deleting(fn (self $m) => $m->attachments()->get()->each->delete());
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BenefitClaimItem::class);
    }
}
