<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime of one employee on one day, counted from CI/CO (App\Support\Kehadiran\LemburHitung)
 * and paid only once approved. Reprocessing a day keeps an approval or a rejection.
 */
#[Fillable(['employee_id', 'date', 'day_type', 'start_date', 'end_date', 'worked_minutes', 'overtime_minutes', 'paid_hours', 'weighted_hours', 'status', 'source', 'notes', 'verified_by', 'verified_at', 'verification_notes'])]
class OvertimeRecord extends Model
{
    public const JENIS_HARI = ['workday' => 'Hari kerja', 'rest_day' => 'Hari istirahat (OFF)', 'public_holiday' => 'Hari libur nasional'];

    public const STATUS = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'start_date' => 'immutable_datetime',
            'end_date' => 'immutable_datetime',
            'paid_hours' => 'float',
            'weighted_hours' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
