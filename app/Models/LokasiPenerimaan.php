<?php

namespace App\Models;

use App\Support\Karyawan\Nrp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NRP digits 2–3: where the employee was hired (e.g. 15 Murung Raya, 03 Rantau, 01/11 Head Office). */
#[Fillable(['kode', 'nama', 'work_location_id'])]
class LokasiPenerimaan extends Model
{
    protected $table = 'lokasi_penerimaans';

    protected static function booted(): void
    {
        static::saved(fn () => Nrp::flush());
        static::deleted(fn () => Nrp::flush());
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** Employees whose NRP carries this code in digits 2–3. */
    public function karyawanCount(): int
    {
        return Karyawan::where('nrp', 'like', "_{$this->kode}%")->count();
    }
}
