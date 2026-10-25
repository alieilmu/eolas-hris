<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Lumpsum of a Cuti Lapangan: per site, POH (lokasi penerimaan) and klasifikasi (Staff / Non Staff). */
#[Fillable(['work_location_id', 'point_of_hire_id', 'employee_class', 'amount'])]
class FieldLeaveAllowance extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
