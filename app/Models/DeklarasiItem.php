<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One amount of a deklarasi: what it is for, and for whom. */
#[Fillable(['deklarasi_id', 'jenis', 'penerima', 'karyawan_anak_id', 'nominal'])]
class DeklarasiItem extends Model
{
    public $timestamps = false;

    /** Rows of the form; perawatan and pengobatan share Plafon Pengobatan, bingkai and lensa are Kacamata. */
    public const JENIS = ['perawatan' => 'Perawatan', 'pengobatan' => 'Pengobatan', 'bingkai' => 'Kacamata – Bingkai', 'lensa' => 'Kacamata – Lensa'];

    public const PENGOBATAN = ['perawatan', 'pengobatan'];

    public const KACAMATA = ['bingkai', 'lensa'];

    public const PENERIMA = ['karyawan' => 'Karyawan', 'pasangan' => 'Istri / Suami', 'anak' => 'Anak'];

    protected function casts(): array
    {
        return ['nominal' => 'integer'];
    }

    public function deklarasi(): BelongsTo
    {
        return $this->belongsTo(Deklarasi::class);
    }

    public function anak(): BelongsTo
    {
        return $this->belongsTo(KaryawanAnak::class, 'karyawan_anak_id');
    }

    /** Which family member: "karyawan", "pasangan" or "anak:{id}". */
    public function memberKey(): string
    {
        return $this->penerima === 'anak' ? "anak:{$this->karyawan_anak_id}" : $this->penerima;
    }
}
