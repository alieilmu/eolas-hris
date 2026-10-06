<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'status', 'color', 'order'])]
class RecruitmentStage extends Model
{
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('order');
    }
}
