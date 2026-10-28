<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use App\Support\Compensation\BenefitClaimCheck;
use App\Support\Notifications\RequestNotifier;
use App\Support\Workflow\Approvable;
use App\Support\Workflow\ChecksDecision;
use App\Support\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

/**
 * A Deklarasi Pengobatan / Kacamata form, booked to a month closing on the 8th. One from the
 * portal goes through its Workflow ("claim"); its last approval checks the plafon again.
 */
#[Fillable([
    'claim_number', 'employee_id', 'date', 'provider_name', 'receipt_count', 'accounting_month', 'benefit_year', 'description', 'created_by',
    'status', 'source', 'verified_by', 'verified_at', 'verification_notes',
])]
class BenefitClaim extends Model implements Approvable, ChecksDecision
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
        static::deleted(function (self $m) {
            RequestNotifier::close("claim:{$m->id}");
            Workflow::withdraw($m, 'Deklarasi dihapus.');
        });
        // Only those of employees the account may see (Administrasi → Pengguna & Akses).
        static::addGlobalScope('access', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('employee');
            }
        });
        // Its attachments (and their files) go with it.
        static::deleting(fn (self $m) => $m->attachments()->get()->each->delete());
    }

    public function workflowNotice(): array
    {
        return RequestNotifier::claimNotice($this);
    }

    /** Still inside the plafon when the last approval makes it count as "terpakai". */
    public function workflowCheck(WorkflowInstance $instance, User $by, bool $final): void
    {
        if (! $final) {
            return;
        }
        $employee = Employee::withoutGlobalScopes()->with(['position', 'jobLevel', 'department', 'section.department', 'workLocation', 'profile.children'])->findOrFail($this->employee_id);
        $items = $this->items->map(fn (BenefitClaimItem $i) => $i->only(['type', 'beneficiary', 'employee_child_id', 'amount']))->all();
        if ($errors = BenefitClaimCheck::errors($employee, $items, $this->benefit_year, $this->id)) {
            throw ValidationException::withMessages(['decision' => $errors]);
        }
    }

    /** Approved (it counts against the plafon) or rejected with the note, as HR verifying it before Workflow; withdrawn: gone. */
    public function workflowCompleted(WorkflowInstance $instance): void
    {
        if ($instance->status === WorkflowInstance::CANCELLED) {
            $this->delete();

            return;
        }
        $last = Workflow::lastDecision($instance);
        $this->update([
            'status' => $instance->status === WorkflowInstance::APPROVED ? 'approved' : 'rejected',
            'verified_by' => $last?->user_id,
            'verified_at' => now(),
            'verification_notes' => $last?->notes,
        ]);
    }

    /** What the step conditions of its flow look at: the total, and medical / optical. */
    public function workflowFacts(): array
    {
        $this->loadMissing('items');
        $optical = $this->items->whereIn('type', BenefitClaimItem::KACAMATA)->isNotEmpty();
        $medical = $this->items->whereIn('type', BenefitClaimItem::PENGOBATAN)->isNotEmpty();

        return ['amount' => (int) $this->items->sum('amount'), 'category' => $optical && ! $medical ? 'optical' : 'medical'];
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
