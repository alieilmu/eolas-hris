<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A circle on the map (e.g. the pit, the site office) where a work location's employees may clock in from the phone. */
#[Fillable(['work_location_id', 'name', 'latitude', 'longitude', 'radius_m'])]
class WorkLocationGeofence extends Model
{
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer'];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }
}
