<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tunjangan Jabatan per SubGroup, a fixed monthly allowance (Pengaturan Kompensasi). */
#[Fillable(['job_level_id', 'amount'])]
class JobLevelAllowance extends Model
{
    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }
}
