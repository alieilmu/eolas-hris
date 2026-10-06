<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_form_id', 'data', 'is_processed', 'submitted_at'])]
class Applicant extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_processed' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }
}
