<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An employee loan, repaid by monthly deductions from pay (loan_installments). */
#[Fillable(['employee_id', 'principal', 'tenor', 'installment', 'start_period', 'purpose', 'status', 'source', 'notes', 'created_by', 'approved_by', 'approved_at', 'rejection_note'])]
class Loan extends Model
{
    public const STATUS = ['pending' => 'Menunggu persetujuan', 'active' => 'Berjalan', 'paid_off' => 'Lunas', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function remaining(): int
    {
        return (int) $this->installments()->where('status', 'scheduled')->sum('amount');
    }
}
