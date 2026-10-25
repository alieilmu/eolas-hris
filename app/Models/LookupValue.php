<?php

namespace App\Models;

use App\Support\Employees\LookupValues;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One value of a choice list (see MasterOptions for the categories). */
#[Fillable(['category', 'value', 'sort_order', 'is_system'])]
class LookupValue extends Model
{
    protected $table = 'lookup_values';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => LookupValues::flush());
        static::deleted(fn () => LookupValues::flush());
    }
}
