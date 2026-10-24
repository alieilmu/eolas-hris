<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tunjangan Jabatan per SubGroup, a fixed monthly allowance (Master Data Comben). */
#[Fillable(['sub_group_id', 'nominal'])]
class TunjanganJabatan extends Model
{
    public function subGroup(): BelongsTo
    {
        return $this->belongsTo(SubGroup::class);
    }
}
