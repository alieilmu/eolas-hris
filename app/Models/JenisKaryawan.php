<?php

namespace App\Models;

use App\Support\Karyawan\Nrp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** First NRP digit: 1 Karyawan Normal, 7 Tenaga Ahli, 8 Mekanik Magang, 9 Operator Magang, ... */
#[Fillable(['kode', 'nama'])]
class JenisKaryawan extends Model
{
    protected $table = 'jenis_karyawans';

    protected static function booted(): void
    {
        static::saved(fn () => Nrp::flush());
        static::deleted(fn () => Nrp::flush());
    }

    /** Employees whose NRP starts with this code. */
    public function karyawanCount(): int
    {
        return Karyawan::where('nrp', 'like', "{$this->kode}%")->count();
    }
}
