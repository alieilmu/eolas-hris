<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Man Power Planning settings (one row): the period a plan is kept per, and the golongan per SubGroup for costs. */
#[Fillable(['period', 'grade_by_job_level'])]
class WorkforcePlanSetting extends Model
{
    public const PERIODE = ['quarterly' => 'Per Kuartal (3 bulan)', 'semiannual' => 'Per Semester (6 bulan)'];

    protected function casts(): array
    {
        return ['grade_by_job_level' => 'array'];
    }

    public static function current(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        return app()->instance(self::class, self::query()->first() ?? self::query()->create(['period' => 'quarterly']));
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }
}
