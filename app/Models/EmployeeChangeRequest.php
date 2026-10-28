<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use App\Support\Employees\DataUpdate;
use App\Support\Notifications\RequestNotifier;
use App\Support\Workflow\Approvable;
use App\Support\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Changes an employee asks for their own data; applied to the profile once its Workflow ("data-change") approves them. */
#[Fillable([
    'employee_id', 'changes', 'children', 'notes', 'status', 'verification_notes', 'verified_by', 'verified_at', 'created_by',
])]
class EmployeeChangeRequest extends Model implements Approvable
{
    protected $table = 'employee_change_requests';

    public const STATUS = ['pending', 'approved', 'rejected'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'children' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function workflowNotice(): array
    {
        return RequestNotifier::updateDataNotice($this);
    }

    /** Approved: written into the profile (a duplicate number stops the approval); rejected: with the note; withdrawn: gone. */
    public function workflowCompleted(WorkflowInstance $instance): void
    {
        if ($instance->status === WorkflowInstance::CANCELLED) {
            $this->delete();

            return;
        }
        $approved = $instance->status === WorkflowInstance::APPROVED;
        if ($approved) {
            DataUpdate::apply($this);
        }
        $last = Workflow::lastDecision($instance);
        $this->update([
            'status' => $approved ? 'approved' : 'rejected',
            'verified_by' => $last?->user_id,
            'verified_at' => now(),
            'verification_notes' => $last?->notes,
        ]);
    }

    protected static function booted(): void
    {
        // Its notifications for the admins are done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'pending' && RequestNotifier::close("data-change-requests:{$m->id}"));
        static::deleted(function (self $m) {
            RequestNotifier::close("data-change-requests:{$m->id}");
            Workflow::withdraw($m, 'Pengajuan dihapus.');
        });
        // Only those of employees the account may see (Administrasi → Pengguna & Akses).
        static::addGlobalScope('access', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('employee');
            }
        });
        // Its supporting documents (and their files) go with it.
        static::deleting(fn (self $m) => $m->attachments()->get()->each->delete());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }
}
