<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A demo account and its workspace (database hris_eloas_t{id}, files under
 * storage/app/tenants/{database}). Always read from the main database ("central").
 */
#[Fillable(['code', 'name', 'email', 'password', 'company_name', 'position', 'phone', 'company_size', 'requirements', 'data_source', 'industry', 'status', 'database', 'notes', 'approved_at', 'provisioned_at', 'expires_at', 'last_login_at'])]
#[Hidden(['password'])]
class TrialAccount extends Model
{
    public const SUMBER = [
        'demo' => 'Data demo sistem',
        'blank' => 'Data sendiri (mulai kosong)',
    ];

    public const STATUS = [
        'pending' => 'Menunggu persetujuan',
        'provisioning' => 'Sedang disiapkan',
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'failed' => 'Gagal disiapkan',
    ];

    protected $connection = 'central';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'approved_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public static function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(6));
        } while (! preg_match('/[a-z]/', $code) || self::where('code', $code)->exists());

        return $code;
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** May sign in and open its workspace now. */
    public function usable(): bool
    {
        return $this->status === 'active' && $this->database !== null && ! $this->expired();
    }

    /** Why the account cannot be used now, for the login form; null when it can. */
    public function blockedReason(): ?string
    {
        return match (true) {
            $this->status === 'pending' => 'Akun demo Anda masih menunggu persetujuan admin Eolas.',
            $this->status === 'provisioning' => 'Workspace demo Anda sedang disiapkan. Coba lagi dalam beberapa saat.',
            $this->status === 'failed' => 'Workspace demo gagal disiapkan. Hubungi admin Eolas.',
            $this->status === 'inactive' => 'Akun demo ini sudah dinonaktifkan.',
            $this->expired() => 'Masa demo akun ini sudah berakhir. Hubungi admin Eolas untuk memperpanjang.',
            default => null,
        };
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at ? max(0, (int) ceil(now()->diffInHours($this->expires_at, false) / 24)) : null;
    }

    /** For the pages (no password). */
    public function toShare(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'company_name' => $this->company_name,
            'position' => $this->position,
            'phone' => $this->phone,
            'company_size' => $this->company_size,
            'requirements' => $this->requirements,
            'data_source' => $this->data_source,
            'source_label' => self::SUMBER[$this->data_source] ?? $this->data_source,
            'industry' => $this->industry,
            'industry_label' => $this->data_source === 'demo' ? (\App\Support\Demo\Industry::LABELS[$this->industry ?? \App\Support\Demo\Industry::DEFAULT] ?? null) : null,
            'status' => $this->expired() && $this->status === 'active' ? 'ends' : $this->status,
            'status_label' => $this->expired() && $this->status === 'active' ? 'Masa demo berakhir' : (self::STATUS[$this->status] ?? $this->status),
            'notes' => $this->notes,
            'expires_at' => $this->expires_at?->toDateTimeString(),
            'days_left' => $this->daysLeft(),
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
