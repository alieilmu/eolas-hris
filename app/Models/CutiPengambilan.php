<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Leave taken outside Trip & Leave: imported from the old Excel monitoring, or typed by HR. */
#[Fillable(['karyawan_id', 'jenis', 'tahun', 'tanggal_awal', 'tanggal_akhir', 'keterangan_tanggal', 'hari', 'sumber', 'catatan', 'created_by'])]
class CutiPengambilan extends Model
{
    protected function casts(): array
    {
        return [
            'tanggal_awal' => 'immutable_date:Y-m-d',
            'tanggal_akhir' => 'immutable_date:Y-m-d',
            'tahun' => 'integer',
            'hari' => 'integer',
        ];
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
