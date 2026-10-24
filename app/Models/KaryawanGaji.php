<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's place in the Struktur Skala Upah (golongan + step) from a date on. The
 * amount itself is read from the scale for the employee's work location and the year
 * (App\Support\Payroll\Gaji), so a new scale column raises everyone on it.
 */
#[Fillable(['karyawan_id', 'skala_upah_id', 'berlaku_mulai', 'alasan', 'catatan', 'created_by'])]
class KaryawanGaji extends Model
{
    public const ALASAN = ['awal' => 'Gaji awal', 'kenaikan' => 'Kenaikan step', 'promosi' => 'Promosi / naik golongan', 'penyesuaian' => 'Penyesuaian'];

    protected function casts(): array
    {
        return ['berlaku_mulai' => 'immutable_date:Y-m-d'];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function skalaUpah(): BelongsTo
    {
        return $this->belongsTo(SkalaUpah::class);
    }
}
