<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Changes an employee asks for their own data; applied to the profile once HR approves. */
#[Fillable([
    'karyawan_id', 'perubahan', 'anak', 'catatan', 'status', 'catatan_verifikasi', 'verified_by', 'verified_at', 'created_by',
])]
class PengajuanUpdateData extends Model
{
    protected $table = 'pengajuan_update_datas';

    public const STATUS = ['diajukan', 'disetujui', 'ditolak'];

    protected function casts(): array
    {
        return [
            'perubahan' => 'array',
            'anak' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Only those of employees the account may see (Settings → Akun & Akses).
        static::addGlobalScope('akses', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('karyawan');
            }
        });
        // Its supporting documents (and their files) go with it.
        static::deleting(fn (self $m) => $m->lampirans()->get()->each->delete());
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function lampirans(): MorphMany
    {
        return $this->morphMany(Lampiran::class, 'lampirable')->orderBy('id');
    }
}
