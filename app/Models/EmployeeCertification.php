<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A licence or certificate an employee holds, until when, with its scan. Seen within the
 * account's locations (through the employee).
 */
#[Fillable(['employee_id', 'certification_type_id', 'number', 'issuer', 'issued_at', 'expires_at', 'notes', 'reminded_days', 'created_by'])]
class EmployeeCertification extends Model
{
    /** Days before expiry a reminder goes out (each once), and on the day itself. */
    public const REMINDERS = [90, 60, 30, 0];

    public const STATUS = ['expired' => 'Kedaluwarsa', 'soon' => '≤ 30 hari', 'upcoming' => '≤ 90 hari', 'valid' => 'Berlaku', 'lifetime' => 'Tanpa masa berlaku'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('access', fn (Builder $q) => $q->whereIn($q->qualifyColumn('employee_id'), Employee::query()->select('id')));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CertificationType::class, 'certification_type_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function daysLeft(?CarbonImmutable $today = null): ?int
    {
        return $this->expires_at ? (int) ($today ?? CarbonImmutable::today())->diffInDays(CarbonImmutable::parse($this->expires_at), false) : null;
    }

    public function status(?CarbonImmutable $today = null): string
    {
        $d = $this->daysLeft($today);

        return match (true) {
            $d === null => 'lifetime',
            $d < 0 => 'expired',
            $d <= 30 => 'soon',
            $d <= 90 => 'upcoming',
            default => 'valid',
        };
    }
}
