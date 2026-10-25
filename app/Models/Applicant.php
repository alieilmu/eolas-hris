<?php

namespace App\Models;

use App\Support\Notifications\RequestNotifier;
use App\Support\Recruitment\Application;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * An application sent through a public form. $data holds the answers per question:
 * {"<field id>": {"label", "type", "kunci", "value"}} — a file answer's value is
 * {"path", "nama", "mime", "ukuran"} on the private disk.
 */
#[Fillable(['job_posting_id', 'data', 'status', 'notes', 'is_processed', 'submitted_at'])]
class Applicant extends Model
{
    /** baru: waiting for HR; diproses: moved to Tracking; ditolak: not taken further. */
    public const STATUS = ['new' => 'Baru', 'in_process' => 'Di Tracking', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_processed' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The admins' notification is done once HR has handled it.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'new' && RequestNotifier::close("applicants:{$m->id}"));
        static::deleted(function (self $m) {
            RequestNotifier::close("applicants:{$m->id}");
            Storage::disk(Application::DISK)->deleteDirectory("recruitment/applicants/{$m->id}");
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(Candidate::class);
    }

    /** The answer of the question marked $kunci (name, email, phone, position_title, portfolio, cv). */
    public function answers(string $field_key): mixed
    {
        foreach ($this->data ?? [] as $answer) {
            if (($answer['field_key'] ?? null) === $field_key && ($answer['value'] ?? null) !== null && $answer['value'] !== '') {
                return $answer['value'];
            }
        }

        return null;
    }

    public function name(): string
    {
        return (string) ($this->answers('name') ?? 'Tanpa nama');
    }
}
