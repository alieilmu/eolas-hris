<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The leave rules (one row): annual quota and its life, and the Cuti Besar every N years. */
#[Fillable([
    'annual_entitlement_days', 'annual_validity_years', 'long_service_interval_years', 'long_service_days', 'long_service_taken_salary_multiplier', 'long_service_cashout_salary_multiplier', 'long_service_decision_months',
])]
class LeavePolicy extends Model
{
    protected function casts(): array
    {
        return [
            'annual_entitlement_days' => 'integer',
            'annual_validity_years' => 'integer',
            'long_service_interval_years' => 'integer',
            'long_service_days' => 'integer',
            'long_service_taken_salary_multiplier' => 'float',
            'long_service_cashout_salary_multiplier' => 'float',
            'long_service_decision_months' => 'integer',
        ];
    }

    private static ?self $current = null;

    public static function current(): self
    {
        return self::$current ??= self::query()->first() ?? self::query()->create([]);
    }

    public static function forget(): void
    {
        self::$current = null;
    }
}
