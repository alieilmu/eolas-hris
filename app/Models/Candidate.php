<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'recruitment_stage_id', 'name', 'email', 'position', 'portfolio_url',
    'cv_url', 'status', 'applied_date', 'order',
])]
class Candidate extends Model
{
    protected function casts(): array
    {
        return [
            'applied_date' => 'date',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'recruitment_stage_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CandidateNote::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class)->latest();
    }

    public function karyawan(): HasOne
    {
        return $this->hasOne(Karyawan::class);
    }
}
