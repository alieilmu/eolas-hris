<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'status', 'color', 'sort_order'])]
class RecruitmentStage extends Model
{
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('sort_order');
    }
}
