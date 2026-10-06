<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'karyawan_profile_id', 'urutan', 'nama', 'tanggal_lahir',
    'pendidikan', 'status_bekerja', 'status_menikah',
])]
class KaryawanAnak extends Model
{
    protected $table = 'karyawan_anak';

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'immutable_date:Y-m-d',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(KaryawanProfile::class, 'karyawan_profile_id');
    }
}
