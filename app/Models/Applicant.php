<?php

namespace App\Models;

use App\Support\Notifikasi\Pengajuan as NotifikasiPengajuan;
use App\Support\Recruitment\Lamaran;
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
#[Fillable(['application_form_id', 'data', 'status', 'catatan', 'is_processed', 'submitted_at'])]
class Applicant extends Model
{
    /** baru: waiting for HR; diproses: moved to Tracking; ditolak: not taken further. */
    public const STATUS = ['baru' => 'Baru', 'diproses' => 'Di Tracking', 'ditolak' => 'Ditolak'];

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
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'baru' && NotifikasiPengajuan::selesai("pelamar:{$m->id}"));
        static::deleted(function (self $m) {
            NotifikasiPengajuan::selesai("pelamar:{$m->id}");
            Storage::disk(Lamaran::DISK)->deleteDirectory("rekrutmen/pelamar/{$m->id}");
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(Candidate::class);
    }

    /** The answer of the question marked $kunci (nama, email, no_hp, posisi, portofolio, cv). */
    public function jawaban(string $kunci): mixed
    {
        foreach ($this->data ?? [] as $answer) {
            if (($answer['kunci'] ?? null) === $kunci && ($answer['value'] ?? null) !== null && $answer['value'] !== '') {
                return $answer['value'];
            }
        }

        return null;
    }

    public function nama(): string
    {
        return (string) ($this->jawaban('nama') ?? 'Tanpa nama');
    }
}
