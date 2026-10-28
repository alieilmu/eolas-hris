<?php

namespace App\Models;

use App\Support\Recruitment\Application;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/** Someone in Pipeline Kandidat: a card in one stage, from an application or added by HR. */
#[Fillable([
    'recruitment_stage_id', 'applicant_id', 'name', 'email', 'phone', 'position', 'portfolio_url',
    'cv_url', 'cv_path', 'cv_filename', 'status', 'applied_date', 'sort_order', 'rejected_at', 'rejection_reason',
])]
class Candidate extends Model
{
    protected function casts(): array
    {
        return [
            'applied_date' => 'immutable_date:Y-m-d',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Its offers go with it, and their approval flows end.
        static::deleting(fn (self $m) => $m->offers()->get()->each->delete());
        // A CV HR uploaded goes with the card (one from the application stays with the application).
        static::deleted(function (self $m) {
            if ($m->cv_path && str_starts_with($m->cv_path, 'recruitment/candidate/')) {
                Storage::disk(Application::DISK)->delete($m->cv_path);
            }
        });
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'recruitment_stage_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CandidateNote::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(CandidateStageHistory::class)->latest();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class)->latest('id');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
