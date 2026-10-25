<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_profile_id', 'sequence_no', 'start_date', 'end_date'])]
class EmploymentContract extends Model
{
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /** "PKWT I" .. "PKWT VI". */
    public static function label(int $periodeKe): string
    {
        return 'PKWT '.(self::ROMAN[$periodeKe - 1] ?? $periodeKe);
    }
}
