<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clock in / clock out from a machine, an imported file, or a correction by HR. */
#[Fillable(['mesin_absen_id', 'pin', 'karyawan_id', 'waktu', 'status_code', 'verify', 'sumber', 'catatan', 'created_by'])]
class AbsenLog extends Model
{
    public const SUMBER = ['mesin' => 'Mesin', 'import' => 'Import', 'manual' => 'Koreksi HR'];

    protected function casts(): array
    {
        return ['waktu' => 'immutable_datetime'];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MesinAbsen::class, 'mesin_absen_id');
    }
}
