<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One position in a plan: how many it needs, and who is expected to leave it this period. */
#[Fillable([
    'mpp_plan_id', 'jabatan_id', 'plan', 'promosi', 'rotasi', 'demosi', 'keluar', 'pensiun', 'sakit',
    'bulan_pindah', 'bulan_penuhi', 'sumber', 'golongan_id', 'keterangan',
])]
class MppPlanItem extends Model
{
    /** The fields HR edits (a change by an account without approval becomes a revision). */
    public const FIELDS = ['plan', 'promosi', 'rotasi', 'demosi', 'keluar', 'pensiun', 'sakit', 'bulan_pindah', 'bulan_penuhi', 'sumber', 'golongan_id', 'keterangan'];

    public const SUMBER = ['internal' => 'Internal (LA)', 'eksternal' => 'Eksternal (ODTA)'];

    protected function casts(): array
    {
        return [
            'plan' => 'integer', 'promosi' => 'integer', 'rotasi' => 'integer', 'demosi' => 'integer', 'keluar' => 'integer',
            'pensiun' => 'integer', 'sakit' => 'integer', 'bulan_pindah' => 'integer', 'bulan_penuhi' => 'integer', 'golongan_id' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MppPlan::class, 'mpp_plan_id');
    }

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class);
    }
}
