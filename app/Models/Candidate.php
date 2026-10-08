<?php

namespace App\Models;

use App\Support\Recruitment\Lamaran;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/** Someone in Recruitment Tracking: a card in one stage, from an application or added by HR. */
#[Fillable([
    'recruitment_stage_id', 'applicant_id', 'name', 'email', 'no_hp', 'position', 'portfolio_url',
    'cv_url', 'cv_path', 'cv_nama', 'status', 'applied_date', 'order', 'ditolak_at', 'alasan_ditolak',
])]
class Candidate extends Model
{
    protected function casts(): array
    {
        return [
            'applied_date' => 'immutable_date:Y-m-d',
            'ditolak_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A CV HR uploaded goes with the card (one from the application stays with the application).
        static::deleted(function (self $m) {
            if ($m->cv_path && str_starts_with($m->cv_path, 'rekrutmen/kandidat/')) {
                Storage::disk(Lamaran::DISK)->delete($m->cv_path);
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
        return $this->hasMany(StatusHistory::class)->latest();
    }

    public function karyawan(): HasOne
    {
        return $this->hasOne(Karyawan::class);
    }
}
