<?php

namespace App\Models;

use App\Support\Notifikasi\Pengajuan as NotifikasiPengajuan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a plan: waiting for an approver (diajukan), or kept as history once
 * decided — saved straight away when an approver made it ($langsung).
 */
#[Fillable(['mpp_plan_id', 'status', 'perubahan', 'langsung', 'catatan', 'catatan_verifikasi', 'created_by', 'verified_by', 'verified_at'])]
class MppRevisi extends Model
{
    public const STATUS = ['diajukan' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan'];

    protected function casts(): array
    {
        return ['perubahan' => 'array', 'langsung' => 'boolean', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // The approvers' notification is done once it is decided or withdrawn.
        static::updated(fn (self $m) => $m->wasChanged('status') && $m->status !== 'diajukan' && NotifikasiPengajuan::selesai("mpp:{$m->id}"));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MppPlan::class, 'mpp_plan_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
