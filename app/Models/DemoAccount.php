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
#[Fillable(['kode', 'nama', 'email', 'password', 'perusahaan', 'jabatan', 'no_hp', 'jumlah_karyawan', 'kebutuhan', 'sumber_data', 'status', 'database', 'catatan', 'approved_at', 'provisioned_at', 'expires_at', 'last_login_at'])]
#[Hidden(['password'])]
class DemoAccount extends Model
{
    public const SUMBER = [
        'demo' => 'Data demo sistem',
        'kosong' => 'Data sendiri (mulai kosong)',
    ];

    public const STATUS = [
        'menunggu' => 'Menunggu persetujuan',
        'menyiapkan' => 'Sedang disiapkan',
        'aktif' => 'Aktif',
        'nonaktif' => 'Nonaktif',
        'gagal' => 'Gagal disiapkan',
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

    public static function newKode(): string
    {
        do {
            $kode = Str::lower(Str::random(6));
        } while (! preg_match('/[a-z]/', $kode) || self::where('kode', $kode)->exists());

        return $kode;
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** May sign in and open its workspace now. */
    public function usable(): bool
    {
        return $this->status === 'aktif' && $this->database !== null && ! $this->expired();
    }

    /** Why the account cannot be used now, for the login form; null when it can. */
    public function blockedReason(): ?string
    {
        return match (true) {
            $this->status === 'menunggu' => 'Akun demo Anda masih menunggu persetujuan admin Eolas.',
            $this->status === 'menyiapkan' => 'Workspace demo Anda sedang disiapkan. Coba lagi dalam beberapa saat.',
            $this->status === 'gagal' => 'Workspace demo gagal disiapkan. Hubungi admin Eolas.',
            $this->status === 'nonaktif' => 'Akun demo ini sudah dinonaktifkan.',
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
            'kode' => $this->kode,
            'nama' => $this->nama,
            'email' => $this->email,
            'perusahaan' => $this->perusahaan,
            'jabatan' => $this->jabatan,
            'no_hp' => $this->no_hp,
            'jumlah_karyawan' => $this->jumlah_karyawan,
            'kebutuhan' => $this->kebutuhan,
            'sumber_data' => $this->sumber_data,
            'sumber_label' => self::SUMBER[$this->sumber_data] ?? $this->sumber_data,
            'status' => $this->expired() && $this->status === 'aktif' ? 'berakhir' : $this->status,
            'status_label' => $this->expired() && $this->status === 'aktif' ? 'Masa demo berakhir' : (self::STATUS[$this->status] ?? $this->status),
            'catatan' => $this->catatan,
            'expires_at' => $this->expires_at?->toDateTimeString(),
            'days_left' => $this->daysLeft(),
            'last_login_at' => $this->last_login_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
