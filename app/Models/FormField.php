<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question of an application form; $kunci says which answer is the name, email, CV, ... */
#[Fillable(['application_form_id', 'label', 'type', 'kunci', 'required', 'options', 'placeholder', 'order'])]
class FormField extends Model
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
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }
}
