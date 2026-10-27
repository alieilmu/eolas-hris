<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An earning, deduction or company contribution on a payslip (code = the component). */
#[Fillable(['payslip_id', 'kind', 'code', 'label', 'amount', 'note', 'sort', 'ref'])]
class PayslipLine extends Model
{
    public $timestamps = false;

    public const KINDS = ['earning' => 'Pendapatan', 'deduction' => 'Potongan', 'company' => 'Ditanggung perusahaan'];
}
