<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use App\Support\Notifications\RequestNotifier;
use App\Support\Time\LeaveTravelWriter;
use App\Support\Workflow\Approvable;
use App\Support\Workflow\Workflow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A business trip (dinas) or leave (cuti); it fills its days in Absensi. One from the employee
 * portal goes through its Workflow ("leave" / "business_trip") and fills Absensi, and counts
 * against the leave balance, only once the last step approves it.
 */
#[Fillable([
    'employee_id', 'type', 'leave_category', 'leave_days', 'annual_leave_deducted', 'long_service_leave_deducted', 'long_service_period', 'start_date', 'end_date', 'origin_city', 'destination_city', 'description', 'cash_advance_status', 'created_by',
    'needs_flight', 'departure_airport', 'arrival_airport', 'is_round_trip', 'return_date', 'return_departure_airport', 'return_arrival_airport',
    'needs_train', 'needs_cash_advance', 'cash_advance_amount', 'field_leave_allowance',
    'outbound_ticket_issued_at', 'outbound_ticket_issued_by', 'return_ticket_issued_at', 'return_ticket_issued_by',
    'status', 'source', 'verified_by', 'verified_at', 'verification_notes',
])]
class LeaveTravelRequest extends Model implements Approvable
{
    /** diajukan: from the employee portal, waiting for HR (not in Absensi yet); disetujui fills Absensi; ditolak does not. */
    public const STATUS = ['pending', 'approved', 'rejected'];

    public const JENIS = ['business_trip' => 'Dinas', 'leave' => 'Cuti'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'needs_flight' => 'boolean',
            'is_round_trip' => 'boolean',
            'needs_train' => 'boolean',
            'return_date' => 'immutable_date:Y-m-d',
            'needs_cash_advance' => 'boolean',
            'cash_advance_amount' => 'integer',
            'leave_days' => 'integer',
            'annual_leave_deducted' => 'integer',
            'long_service_leave_deducted' => 'integer',
            'long_service_period' => 'integer',
            'field_leave_allowance' => 'integer',
            'outbound_ticket_issued_at' => 'datetime',
            'return_ticket_issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Its notifications for the admins are done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'pending' && RequestNotifier::close("leave_travel:{$m->id}"));
        static::deleted(fn (self $m) => RequestNotifier::close("leave_travel:{$m->id}"));
        // Only those of employees the account may see (Administrasi → Pengguna & Akses).
        static::addGlobalScope('access', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('employee');
            }
        });
        // Its attachments (and their files) go with it.
        static::deleting(fn (self $m) => $m->attachments()->get()->each->delete());
        // Deleted while waiting: its flow ends with it.
        static::deleted(fn (self $m) => Workflow::withdraw($m, 'Pengajuan dihapus.'));
    }

    public function workflowNotice(): array
    {
        return RequestNotifier::leaveTravelNotice($this);
    }

    /** The last step approved: as HR verifying it before Workflow (Absensi filled); rejected: with the note; withdrawn: gone. */
    public function workflowCompleted(WorkflowInstance $instance): void
    {
        if ($instance->status === WorkflowInstance::CANCELLED) {
            $this->delete();

            return;
        }
        $approved = $instance->status === WorkflowInstance::APPROVED;
        if ($approved) {
            LeaveTravelWriter::codeFor($this->type);
        }
        $last = Workflow::lastDecision($instance);
        $this->update([
            'status' => $approved ? 'approved' : 'rejected',
            'verified_by' => $last?->user_id,
            'verified_at' => now(),
            'verification_notes' => $last?->notes,
        ]);
        LeaveTravelWriter::fill($this);
    }

    /** What the step conditions of its flow look at. */
    public function workflowFacts(): array
    {
        return array_filter([
            'days' => $this->type === 'leave' ? ($this->leave_days ?? $this->durationDays()) : $this->durationDays(),
            'category' => $this->leave_category,
            'amount' => $this->needs_cash_advance ? (int) $this->cash_advance_amount : null,
        ], fn ($v) => $v !== null);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function outboundTicketIssuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outbound_ticket_issued_by');
    }

    public function returnTicketIssuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_ticket_issued_by');
    }

    /** A ticket to buy going (flight or train), and coming back (the return flight of a PP). */
    public function hasTicket(string $direction): bool
    {
        return $direction === 'outbound' ? $this->needs_flight || $this->needs_train : $this->needs_flight && $this->is_round_trip;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance_records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** Approved trips: the ones in Absensi and Man Onsite. */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /** Trips that have at least one day between $from and $to. */
    public function scopeOverlapping(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $query->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    /** Akan berangkat / Dalam perjalanan / Selesai, seen from $today. */
    public function travelStatus(CarbonInterface $today): string
    {
        return match (true) {
            $today->lt($this->start_date) => 'soon',
            $today->gt($this->end_date) => 'end_date',
            default => 'ongoing',
        };
    }

    public function durationDays(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }
}
