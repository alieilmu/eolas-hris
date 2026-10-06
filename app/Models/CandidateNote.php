<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_id', 'author', 'content'])]
class CandidateNote extends Model
{
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
