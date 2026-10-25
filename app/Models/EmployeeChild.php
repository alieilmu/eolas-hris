<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_profile_id', 'sort_order', 'name', 'birth_date',
    'education_level', 'employment_status', 'marital_status',
])]
class EmployeeChild extends Model
{
    protected $table = 'employee_children';

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date:Y-m-d',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }
}
