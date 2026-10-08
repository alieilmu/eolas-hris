<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A vacancy's application form, published at /lowongan/{id} while it is open. */
#[Fillable(['title', 'description', 'posisi', 'lokasi', 'jabatan_id', 'batas_lamaran', 'is_open'])]
class ApplicationForm extends Model
{
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'batas_lamaran' => 'immutable_date:Y-m-d',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    /** The position it recruits for, when opened from Man Power Planning. */
    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /** Open, and its closing date (if any) not passed. */
    public function accepting(): bool
    {
        return $this->is_open && (! $this->batas_lamaran || $this->batas_lamaran->gte(today()));
    }
}
