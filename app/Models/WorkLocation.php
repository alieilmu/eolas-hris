<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'kode', 'type', 'order', 'skema_kerja', 'jam_kerja_harian'])]
class WorkLocation extends Model
{
    public const TYPE_HEAD_OFFICE = 'Head Office';

    /** Working-time schemes: the basis of normal hours and overtime (Attendance → Lembur). */
    public const SKEMA_KERJA = [
        '5_hari' => '5 hari kerja · 8 jam/hari (PP 35/2021)',
        '6_hari' => '6 hari kerja · 7 jam/hari (PP 35/2021)',
        'esdm' => 'Waktu kerja tertentu ESDM · roster (Kepmenakertrans 234/2003)',
    ];

    protected function casts(): array
    {
        return ['jam_kerja_harian' => 'float'];
    }

    public function karyawans(): HasMany
    {
        return $this->hasMany(Karyawan::class);
    }

    public function subLocations(): HasMany
    {
        return $this->hasMany(SubLocation::class)->orderBy('order')->orderBy('name');
    }

    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class);
    }

    /** NRP location codes that stand for this place (decides Lokal / Kiriman). */
    public function lokasiPenerimaans(): HasMany
    {
        return $this->hasMany(LokasiPenerimaan::class);
    }

    public function isHeadOffice(): bool
    {
        return $this->type === self::TYPE_HEAD_OFFICE;
    }

    /** How the place appears in a title: its short code (RMTU) or else its name. */
    public function titleName(): string
    {
        return $this->kode ?: $this->name;
    }
}
