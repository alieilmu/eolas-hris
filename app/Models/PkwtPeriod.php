<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['karyawan_profile_id', 'periode_ke', 'tanggal_kontrak', 'tanggal_berakhir'])]
class PkwtPeriod extends Model
{
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI'];

    protected function casts(): array
    {
        return [
            'tanggal_kontrak' => 'immutable_date:Y-m-d',
            'tanggal_berakhir' => 'immutable_date:Y-m-d',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(KaryawanProfile::class, 'karyawan_profile_id');
    }

    /** "PKWT I" .. "PKWT VI". */
    public static function label(int $periodeKe): string
    {
        return 'PKWT '.(self::ROMAN[$periodeKe - 1] ?? $periodeKe);
    }
}
