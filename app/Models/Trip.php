<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A business trip (dinas) or leave (cuti); it fills its days in Absensi. */
#[Fillable([
    'karyawan_id', 'jenis', 'kategori_cuti', 'hari_cuti', 'potong_tahunan', 'potong_besar', 'cuti_besar_periode', 'tanggal_awal', 'tanggal_akhir', 'kota_asal', 'kota_tujuan', 'keterangan', 'status_uang', 'created_by',
    'tiket_pesawat', 'bandara_asal', 'bandara_tujuan', 'pesawat_pp', 'pulang_tanggal', 'pulang_bandara_asal', 'pulang_bandara_tujuan',
    'tiket_kereta', 'uang_muka', 'uang_muka_nominal',
    'status', 'sumber', 'verified_by', 'verified_at', 'catatan_verifikasi',
])]
class Trip extends Model
{
    /** diajukan: from the employee portal, waiting for HR (not in Absensi yet); disetujui fills Absensi; ditolak does not. */
    public const STATUS = ['diajukan', 'disetujui', 'ditolak'];

    public const JENIS = ['dinas' => 'Dinas', 'cuti' => 'Cuti'];

    protected function casts(): array
    {
        return [
            'tanggal_awal' => 'immutable_date:Y-m-d',
            'tanggal_akhir' => 'immutable_date:Y-m-d',
            'tiket_pesawat' => 'boolean',
            'pesawat_pp' => 'boolean',
            'tiket_kereta' => 'boolean',
            'pulang_tanggal' => 'immutable_date:Y-m-d',
            'uang_muka' => 'boolean',
            'uang_muka_nominal' => 'integer',
            'hari_cuti' => 'integer',
            'potong_tahunan' => 'integer',
            'potong_besar' => 'integer',
            'cuti_besar_periode' => 'integer',
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

    public function absensis(): HasMany
    {
        return $this->hasMany(Absensi::class);
    }

    /** Approved trips: the ones in Absensi and Man Onsite. */
    public function scopeDisetujui(Builder $query): void
    {
        $query->where('status', 'disetujui');
    }

    /** Trips that have at least one day between $from and $to. */
    public function scopeOverlapping(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $query->whereDate('tanggal_awal', '<=', $to)->whereDate('tanggal_akhir', '>=', $from);
    }

    /** Akan berangkat / Dalam perjalanan / Selesai, seen from $today. */
    public function statusPerjalanan(CarbonInterface $today): string
    {
        return match (true) {
            $today->lt($this->tanggal_awal) => 'akan',
            $today->gt($this->tanggal_akhir) => 'selesai',
            default => 'berjalan',
        };
    }

    public function lamaHari(): int
    {
        return (int) $this->tanggal_awal->diffInDays($this->tanggal_akhir) + 1;
    }
}
