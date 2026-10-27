<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Input Variabel: an amount of a component for one employee in one period, paid once approved. */
#[Fillable(['employee_id', 'period', 'payroll_component_id', 'amount', 'notes', 'status', 'source', 'created_by', 'approved_by', 'approved_at', 'rejection_note', 'payslip_id', 'source_ref'])]
class PayrollInput extends Model
{
    public const STATUS = ['pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Paid in an approved payroll run: no longer changed. */
    public function locked(): bool
    {
        return $this->payslip_id !== null;
    }
}
