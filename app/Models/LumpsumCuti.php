<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Lumpsum of a Cuti Lapangan: per site, POH (lokasi penerimaan) and klasifikasi (Staff / Non Staff). */
#[Fillable(['work_location_id', 'lokasi_penerimaan_id', 'klasifikasi', 'nominal'])]
class LumpsumCuti extends Model
{
    protected function casts(): array
    {
        return ['nominal' => 'integer'];
    }
}
