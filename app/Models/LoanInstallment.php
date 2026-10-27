<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month's repayment of a loan: scheduled, deducted from a payslip, or settled outside pay. */
#[Fillable(['loan_id', 'sequence', 'period', 'amount', 'status', 'payslip_id', 'notes'])]
class LoanInstallment extends Model
{
    public const STATUS = ['scheduled' => 'Terjadwal', 'deducted' => 'Dipotong dari gaji', 'settled' => 'Dilunasi di luar gaji'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
