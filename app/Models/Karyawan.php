<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'nrp', 'name', 'email', 'status', 'jabatan_id', 'work_location_id', 'candidate_id',
    'sub_location_id', 'sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id', 'bidang',
    'tanggal_keluar', 'alasan_keluar', 'keterangan_keluar',
])]
class Karyawan extends Model
{
    protected $table = 'karyawans';

    public const STATUS_AKTIF = 'Aktif';

    public const STATUS_NONAKTIF = 'Nonaktif';

    /** Excluded from the dashboard, Data Lengkap and org-chart slots. */
    public const STATUS_KELUAR = 'Keluar';

    /**
     * The employee's own placement — where they work, their SubGroup and what it
     * names positions after. They hold a position (jabatan_id) once one matching
     * it exists in the plan; until then they wait to be placed in the chart.
     */
    public const PLACEMENT_COLUMNS = [
        'work_location_id', 'sub_location_id', 'sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id', 'bidang',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_keluar' => 'immutable_date:Y-m-d',
        ];
    }

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class);
    }

    /** Where this employee works; a position can be filled at several sites. */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** A Sub Lokasi Kerja of their work location, when assigned away from the site itself. */
    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(SubLocation::class);
    }

    public function subGroup(): BelongsTo
    {
        return $this->belongsTo(SubGroup::class);
    }

    public function bod(): BelongsTo
    {
        return $this->belongsTo(Bod::class);
    }

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    public function departemen(): BelongsTo
    {
        return $this->belongsTo(Departemen::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return array<string, mixed> the placement, as JabatanComposer reads it */
    public function placement(): array
    {
        return $this->only(self::PLACEMENT_COLUMNS);
    }

    /** The departemen of their placement: the one given, or the one of their section. */
    public function placementDepartemen(): ?Departemen
    {
        return $this->departemen ?? $this->section?->departemen;
    }

    public function isPlaced(): bool
    {
        return $this->jabatan_id !== null;
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(KaryawanProfile::class);
    }

    /** Leave taken outside Trip & Leave (imported history, or typed by HR). */
    public function cutiPengambilans(): HasMany
    {
        return $this->hasMany(CutiPengambilan::class);
    }

    protected static function booted(): void
    {
        // Settings → Akun & Akses: an Admin sees the employees of their locations, a Karyawan only themselves.
        static::addGlobalScope('akses', fn (Builder $query) => AccessScope::restrict($query));
    }

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', self::STATUS_AKTIF);
    }

    public function scopeKeluar(Builder $query): void
    {
        $query->where('status', self::STATUS_KELUAR);
    }
}
