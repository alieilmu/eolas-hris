<?php

namespace App\Models;

use App\Support\Workflow\Approvable;
use App\Support\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The offer to a candidate — position, location, salary, start date — approved through its
 * Workflow ("job-offer") before the candidate is hired. Its approvers are found as for an
 * employee in the position offered (e.g. the superior of that position: the hiring manager).
 */
#[Fillable(['candidate_id', 'position_id', 'work_location_id', 'salary', 'start_date', 'notes', 'status', 'created_by', 'decided_by', 'decided_at', 'decision_notes'])]
class JobOffer extends Model implements Approvable
{
    public const STATUS = ['pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date:Y-m-d', 'decided_at' => 'datetime', 'salary' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $m) => Workflow::withdraw($m, 'Penawaran dihapus.'));
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** "Budi Santoso · Operator Produksi · Rp 7.500.000 · mulai 01.12.2026" */
    public function summary(): string
    {
        $this->loadMissing('candidate', 'position');

        return collect([
            $this->candidate?->name,
            $this->position?->name,
            $this->salary ? 'Rp '.number_format($this->salary, 0, ',', '.') : null,
            $this->start_date ? 'mulai '.$this->start_date->format('d.m.Y') : null,
        ])->filter()->implode(' · ');
    }

    public function workflowNotice(): array
    {
        return ['type' => 'job-offer', 'ref' => "job-offer:{$this->id}", 'title' => 'Penawaran Kerja', 'body' => $this->summary(),
            'url' => "/recruitment/pipeline?view={$this->candidate_id}"];
    }

    public function workflowCompleted(WorkflowInstance $instance): void
    {
        $last = Workflow::lastDecision($instance);
        $this->update([
            'status' => $instance->status,
            'decided_by' => $last?->user_id,
            'decided_at' => now(),
            'decision_notes' => $last?->notes,
        ]);
        $this->loadMissing('candidate');
        CandidateStageHistory::create(['candidate_id' => $this->candidate_id, 'from' => $this->candidate?->status,
            'to' => 'Penawaran '.mb_strtolower(self::STATUS[$instance->status]), 'changed_by' => $last?->user_name ?? 'Sistem']);
    }

    /** The candidate as the approvers see them: standing in the position offered, at the location offered. */
    public function standIn(): Employee
    {
        $position = $this->position_id ? Position::find($this->position_id) : null;

        return (new Employee)->forceFill([
            'position_id' => $this->position_id,
            'work_location_id' => $this->work_location_id ?? $position?->work_location_id,
            'job_level_id' => $position?->job_level_id,
        ]);
    }
}
