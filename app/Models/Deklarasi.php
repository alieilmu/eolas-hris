<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A Deklarasi Pengobatan / Kacamata form, booked to a month closing on the 8th. */
#[Fillable([
    'nomor', 'karyawan_id', 'tanggal', 'tempat', 'jumlah_kwitansi', 'bulan_buku', 'tahun_plafon', 'keterangan', 'created_by',
    'status', 'sumber', 'verified_by', 'verified_at', 'catatan_verifikasi',
])]
class Deklarasi extends Model
{
    /** diajukan: from the employee portal, waiting for HR; disetujui counts; ditolak does not. */
    public const STATUS = ['diajukan', 'disetujui', 'ditolak'];

    protected function casts(): array
    {
        return ['tanggal' => 'immutable_date:Y-m-d'];
    }

    protected static function booted(): void
    {
        // Only those of employees the account may see (Settings → Akun & Akses).
        static::addGlobalScope('akses', function (Builder $query) {
            if (AccessScope::restrictedUser()) {
                $query->whereHas('karyawan');
            }
        });
        // Its attachments (and their files) go with it.
        static::deleting(fn (self $m) => $m->lampirans()->get()->each->delete());
    }

    public function lampirans(): MorphMany
    {
        return $this->morphMany(Lampiran::class, 'lampirable')->orderBy('id');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeklarasiItem::class);
    }
}
