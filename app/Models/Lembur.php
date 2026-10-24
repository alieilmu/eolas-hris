<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime of one employee on one day, counted from CI/CO (App\Support\Kehadiran\LemburHitung)
 * and paid only once approved. Reprocessing a day keeps an approval or a rejection.
 */
#[Fillable(['karyawan_id', 'tanggal', 'jenis_hari', 'mulai', 'selesai', 'menit_kerja', 'menit_lembur', 'jam_dibayar', 'jam_terhitung', 'status', 'sumber', 'catatan', 'verified_by', 'verified_at', 'catatan_verifikasi'])]
class Lembur extends Model
{
    public const JENIS_HARI = ['kerja' => 'Hari kerja', 'istirahat' => 'Hari istirahat (OFF)', 'libur' => 'Hari libur nasional'];

    public const STATUS = ['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'immutable_date:Y-m-d',
            'mulai' => 'immutable_datetime',
            'selesai' => 'immutable_datetime',
            'jam_dibayar' => 'float',
            'jam_terhitung' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
