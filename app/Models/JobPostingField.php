<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question of an application form; $kunci says which answer is the name, email, CV, ... */
#[Fillable(['job_posting_id', 'label', 'type', 'field_key', 'required', 'options', 'placeholder', 'sort_order'])]
class JobPostingField extends Model
{
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }
}
