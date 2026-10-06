<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee subgroup: its layer 1–6 (the higher the more senior) and how the
 * names of its positions are composed — the title in front (gelar) and where
 * the rest comes from (sumber).
 */
#[Fillable(['nama', 'layer', 'sumber', 'gelar', 'gelar_ho'])]
class SubGroup extends Model
{
    public const MIN_LAYER = 1;

    public const MAX_LAYER = 6;

    /** From this layer up a position takes one active employee. */
    public const SINGLE_HOLDER_MIN_LAYER = 3;

    /** Where the rest of a position's name — and its organisation unit — come from. */
    public const SUMBER = [
        'bod' => 'BOD (nama BOD)',
        'divisi' => 'Divisi',
        'departemen' => 'Departemen',
        'section' => 'Section',
        'work_location' => 'Work Location',
        'bidang' => 'Nama Jabatan (Section opsional)',
    ];

    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class);
    }

    public function isSingleHolder(): bool
    {
        return $this->layer >= self::SINGLE_HOLDER_MIN_LAYER;
    }

    /** The title for a position at this location: OFFICER at Head Office, GROUP LEADER elsewhere, ... */
    public function gelarAt(?WorkLocation $location): ?string
    {
        return $location?->isHeadOffice() && $this->gelar_ho ? $this->gelar_ho : $this->gelar;
    }
}
