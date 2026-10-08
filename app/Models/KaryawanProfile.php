<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The 19 data groups of one employee. Usia, masa kerja and status akhir PKWT
 * are computed here on read and never stored.
 */
class KaryawanProfile extends Model
{
    protected $table = 'karyawan_profiles';

    /** Over 100 flat columns; request data is always validated before it gets here. */
    protected $guarded = ['id', 'karyawan_id'];

    public const PKWT_MAX_PERIODS = 6;

    /** Date columns, serialized as plain Y-m-d so no timezone shift can move them a day. */
    public const DATE_FIELDS = [
        'tanggal_lahir', 'tanggal_masuk', 'tanggal_perkawinan', 'tanggal_sk',
        'npwp_tanggal_daftar', 'bpjs_tk_tanggal_daftar', 'tanggal_lahir_pasangan',
        'tanggal_lahir_ayah', 'tanggal_lahir_ibu', 'tanggal_lahir_ayah_mertua', 'tanggal_lahir_ibu_mertua',
        'sakit_mulai', 'sakit_selesai',
    ];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::DATE_FIELDS, 'immutable_date:Y-m-d'),
            'status_pegawai_tetap' => 'boolean',
        ];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function pkwtPeriods(): HasMany
    {
        return $this->hasMany(PkwtPeriod::class)->orderBy('periode_ke');
    }

    public function anak(): HasMany
    {
        return $this->hasMany(KaryawanAnak::class)->orderBy('urutan');
    }

    protected function usia(): Attribute
    {
        return Attribute::get(function (): ?int {
            if (! $this->tanggal_lahir || $this->tanggal_lahir->isFuture()) {
                return null;
            }

            return $this->tanggal_lahir->age;
        });
    }

    /** "X tahun Y bulan" from tanggal_masuk until today. */
    protected function masaKerja(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->masaKerjaPada(today()));
    }

    /** "X tahun Y bulan" from tanggal_masuk until $end, e.g. the exit date. */
    public function masaKerjaPada(CarbonInterface $end): ?string
    {
        if (! $this->tanggal_masuk || $this->tanggal_masuk->isAfter($end)) {
            return null;
        }

        $diff = $this->tanggal_masuk->diff($end);

        return "{$diff->y} tahun {$diff->m} bulan";
    }

    /** The contract that ends last, whether or not it is still running. */
    public function latestPkwt(): ?PkwtPeriod
    {
        return $this->pkwtPeriods->sortByDesc('tanggal_berakhir')->first();
    }

    /**
     * State of the latest contract only: "Aktif", "Berakhir" or "-". Deliberately
     * independent of status_pegawai_tetap so HR still sees a lapsed PKWT after
     * appointment, e.g. a renewal that was never signed. A contract is still
     * active on its end date.
     */
    protected function statusAkhirPkwt(): Attribute
    {
        return Attribute::get(function (): string {
            $latest = $this->latestPkwt();

            if (! $latest) {
                return '-';
            }

            return $latest->tanggal_berakhir->gte(today()) ? 'Aktif' : 'Berakhir';
        });
    }
}
