<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The wage of one skala upah line in one column. */
#[Fillable(['skala_upah_id', 'kolom_id', 'nominal'])]
class SkalaUpahNilai extends Model
{
    protected $table = 'skala_upah_nilai';

    public $timestamps = false;
}
