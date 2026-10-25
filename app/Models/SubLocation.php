<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sub Lokasi Kerja: a place under a work location (a port, an office in town,
 * ...) for people assigned away from their site. Its positions sit in its own
 * box in the plan, under the position it reports to (usually the Project Manager).
 */
#[Fillable(['work_location_id', 'name', 'parent_position_id', 'sort_order'])]
class SubLocation extends Model
{
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** The position its box hangs under in the plan; null = not in the plan yet. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'parent_position_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
