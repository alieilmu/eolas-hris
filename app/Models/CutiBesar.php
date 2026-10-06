<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The choice for one Cuti Besar entitlement: diambil (the days + 1x gapok) or
 * diuangkan (2x gapok). From the portal it waits for HR (diajukan).
 */
#[Fillable(['karyawan_id', 'periode_ke', 'keputusan', 'status', 'sumber', 'catatan', 'verified_by', 'verified_at'])]
class CutiBesar extends Model
{
    public const KEPUTUSAN = ['diambil' => 'Diambil', 'diuangkan' => 'Diuangkan'];

    protected function casts(): array
    {
        return ['periode_ke' => 'integer', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('akses', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('karyawan');
            }
        });
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }
}
