<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Man Power Planning settings (one row): the period a plan is kept per, and the golongan per SubGroup for costs. */
#[Fillable(['periode', 'golongan_sub_group'])]
class MppAturan extends Model
{
    public const PERIODE = ['kuartal' => 'Per Kuartal (3 bulan)', 'semester' => 'Per Semester (6 bulan)'];

    protected function casts(): array
    {
        return ['golongan_sub_group' => 'array'];
    }

    public static function current(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        return app()->instance(self::class, self::query()->first() ?? self::query()->create(['periode' => 'kuartal']));
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }
}
