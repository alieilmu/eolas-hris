<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuti lapangan of a work location (or one sub location): leave days + travel out + travel back. */
#[Fillable(['work_location_id', 'sub_location_id', 'hari_cuti', 'hari_berangkat', 'hari_kembali'])]
class CutiLapangan extends Model
{
    protected function casts(): array
    {
        return ['hari_cuti' => 'integer', 'hari_berangkat' => 'integer', 'hari_kembali' => 'integer'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(SubLocation::class);
    }

    public function total(): int
    {
        return $this->hari_cuti + $this->hari_berangkat + $this->hari_kembali;
    }
}
