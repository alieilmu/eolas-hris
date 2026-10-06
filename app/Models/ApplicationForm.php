<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'is_open'])]
class ApplicationForm extends Model
{
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }
}
