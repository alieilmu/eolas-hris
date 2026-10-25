<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A vacancy's application form, published at /lowongan/{id} while it is open. */
#[Fillable(['title', 'description', 'position_title', 'location', 'position_id', 'closing_date', 'is_open'])]
class JobPosting extends Model
{
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'closing_date' => 'immutable_date:Y-m-d',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(JobPostingField::class)->orderBy('sort_order');
    }

    /** The position it recruits for, when opened from Man Power Planning. */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /** Open, and its closing date (if any) not passed. */
    public function accepting(): bool
    {
        return $this->is_open && (! $this->closing_date || $this->closing_date->gte(today()));
    }
}
