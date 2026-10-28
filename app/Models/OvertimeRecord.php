<?php

namespace App\Models;

use App\Support\Workflow\Approvable;
use App\Support\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime of one employee on one day, counted from CI/CO (App\Support\Kehadiran\LemburHitung)
 * and paid only once approved. Reprocessing a day keeps an approval or a rejection. Overtime
 * counted since Workflow is approved through its flow ("overtime"), started by the processing.
 */
#[Fillable(['employee_id', 'date', 'day_type', 'start_date', 'end_date', 'worked_minutes', 'overtime_minutes', 'paid_hours', 'weighted_hours', 'status', 'source', 'notes', 'verified_by', 'verified_at', 'verification_notes'])]
class OvertimeRecord extends Model implements Approvable
{
    public const JENIS_HARI = ['workday' => 'Hari kerja', 'rest_day' => 'Hari istirahat (OFF)', 'public_holiday' => 'Hari libur nasional'];

    public const STATUS = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'start_date' => 'immutable_datetime',
            'end_date' => 'immutable_datetime',
            'paid_hours' => 'float',
            'weighted_hours' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    public function workflowNotice(): array
    {
        return ['type' => 'overtime', 'ref' => "overtime:{$this->id}", 'title' => 'Lembur', 'body' => $this->summary(), 'url' => '/time/clock?tab=overtime&status=pending'];
    }

    public function workflowCompleted(WorkflowInstance $instance): void
    {
        if ($instance->status === WorkflowInstance::CANCELLED) {
            return;
        }
        $last = Workflow::lastDecision($instance);
        $this->update(['status' => $instance->status, 'verified_by' => $last?->user_id, 'verified_at' => now(), 'verification_notes' => $last?->notes]);
    }

    /** What the step conditions of its flow look at. */
    public function workflowFacts(): array
    {
        return ['hours' => (float) $this->paid_hours, 'category' => $this->day_type];
    }

    /** "3,5 jam · 12.10.2026 · Hari kerja" */
    public function summary(): string
    {
        return str_replace('.', ',', (string) round((float) $this->paid_hours, 2)).' jam · '.$this->date->format('d.m.Y').' · '.(self::JENIS_HARI[$this->day_type] ?? $this->day_type);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
