<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The Man Power Planning of one work location for one period (a quarter or a semester). */
#[Fillable(['work_location_id', 'jenis', 'tahun', 'ke', 'mulai', 'selesai', 'dari_plan_id'])]
class MppPlan extends Model
{
    protected function casts(): array
    {
        return ['mulai' => 'immutable_date:Y-m-d', 'selesai' => 'immutable_date:Y-m-d', 'tahun' => 'integer', 'ke' => 'integer'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MppPlanItem::class);
    }

    public function revisis(): HasMany
    {
        return $this->hasMany(MppRevisi::class)->latest('id');
    }

    /** "Q2 2027", "S1 2027". */
    public function label(): string
    {
        return ($this->jenis === 'semester' ? 'S' : 'Q')."{$this->ke} {$this->tahun}";
    }

    /** "2027-Q2", as in the address. */
    public function kode(): string
    {
        return "{$this->tahun}-".($this->jenis === 'semester' ? 'S' : 'Q').$this->ke;
    }
}
