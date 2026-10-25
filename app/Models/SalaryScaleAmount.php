<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The wage of one skala upah line in one column. */
#[Fillable(['salary_scale_id', 'salary_scale_period_id', 'amount'])]
class SalaryScaleAmount extends Model
{
    protected $table = 'salary_scale_amounts';

    public $timestamps = false;
}
