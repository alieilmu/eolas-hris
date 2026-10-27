<?php

namespace App\Models;

use App\Support\Payroll\PayrollPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proses Gaji: one payroll period for all work locations (work_location_id null) or one.
 * draft → calculating → calculated → submitted → approved → paid → locked; a submitted run
 * sent back returns to calculated. Only draft and calculated runs are worked out again.
 */
#[Fillable([
    'period', 'type', 'work_location_id', 'period_start', 'period_end', 'pay_date', 'status', 'employee_count', 'progress', 'totals', 'notes', 'rejection_note',
    'created_by', 'calculated_by', 'calculated_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
    'paid_by', 'paid_at', 'locked_by', 'locked_at',
])]
class PayrollRun extends Model
{
    public const TYPES = ['regular' => 'Gaji bulanan'];

    public const STATUS = [
        'draft' => 'Draft',
        'calculating' => 'Sedang dihitung',
        'calculated' => 'Sudah dihitung',
        'submitted' => 'Diajukan',
        'approved' => 'Disetujui',
        'paid' => 'Dibayar',
        'locked' => 'Terkunci',
    ];

    /** Steps whose slips count as paid out: the employee sees them, December's tax builds on them. */
    public const FINAL = ['approved', 'paid', 'locked'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'pay_date' => 'date', 'totals' => 'array',
            'calculated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'locked_at' => 'datetime',
        ];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function periodOf(): PayrollPeriod
    {
        [$y, $m] = array_map('intval', explode('-', $this->period));

        return PayrollPeriod::of($y, $m, PayrollSetting::current()->cutoff_day);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'calculated'], true);
    }

    public function label(): string
    {
        return $this->periodOf()->label().' · '.($this->workLocation?->name ?? 'Semua lokasi');
    }
}
