<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'kode', 'type', 'order'])]
class WorkLocation extends Model
{
    public const TYPE_HEAD_OFFICE = 'Head Office';

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
