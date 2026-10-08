<?php

namespace App\Support\Notifikasi;

use App\Models\Applicant;
use App\Models\CutiBesar;
use App\Models\Deklarasi;
use App\Models\DeklarasiItem;
use App\Models\Karyawan;
use App\Models\MppRevisi;
use App\Models\PengajuanUpdateData;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\PengajuanMasuk;
use App\Support\Access\AccessScope;
use App\Support\Cuti\Cuti;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Notifications for a pengajuan from the employee portal. They go to the admins who may
 * verify it — "ubah" on its menu, and the employee inside their locations — and are
 * closed (read for everyone) once HR has decided, or the employee withdrew it.
 */
class Pengajuan
{
    public static function updateData(PengajuanUpdateData $p): void
    {
        $n = count($p->perubahan ?? []) + count($p->anak ?? []);
        self::send('update-data', "update-data:{$p->id}", 'update-data', $p->karyawan,
            'Pengajuan Update Data', "mengajukan {$n} perubahan data", '/manpower/pengajuan-data');
    }

    public static function deklarasi(Deklarasi $d): void
    {
        $d->loadMissing('items');
        $kacamata = $d->items->whereIn('jenis', DeklarasiItem::KACAMATA)->isNotEmpty();
        $pengobatan = $d->items->whereIn('jenis', DeklarasiItem::PENGOBATAN)->isNotEmpty();
        $judul = 'Deklarasi '.match (true) {
            $kacamata && $pengobatan => 'Pengobatan & Kacamata',
            $kacamata => 'Kacamata',
            default => 'Pengobatan',
        };
        self::send('deklarasi', "deklarasi:{$d->id}", 'deklarasi', $d->karyawan,
            $judul, "{$d->nomor} · Rp ".number_format((int) $d->items->sum('nominal'), 0, ',', '.'), '/comben/deklarasi?status=diajukan');
    }

    public static function trip(Trip $t): void
    {
        $tanggal = $t->tanggal_awal->format('d.m.Y').($t->tanggal_akhir->ne($t->tanggal_awal) ? ' – '.$t->tanggal_akhir->format('d.m.Y') : '');
        $isi = $t->jenis === 'cuti'
            ? (Cuti::KATEGORI[$t->kategori_cuti] ?? 'Cuti').' '.($t->hari_cuti ?? $t->lamaHari())." hari, {$tanggal}"
            : "ke {$t->kota_tujuan}, {$tanggal}".($t->uang_muka ? ' · uang muka Rp '.number_format((int) $t->uang_muka_nominal, 0, ',', '.') : '');
        self::send($t->jenis, "trip:{$t->id}", 'pengajuan-dinas', $t->karyawan,
            'Pengajuan '.Trip::JENIS[$t->jenis], $isi, "/attendance/pengajuan-dinas?jenis={$t->jenis}&status=diajukan");
    }

    public static function cutiBesar(CutiBesar $c): void
    {
        self::send('cuti-besar', "cuti-besar:{$c->id}", 'cuti', $c->karyawan,
            'Pilihan Cuti Besar', 'memilih '.(CutiBesar::KEPUTUSAN[$c->keputusan] ?? $c->keputusan)." (periode ke-{$c->periode_ke})", '/comben/cuti?tab=besar&status=menunggu');
    }

    /** A new application from the public form (no employee yet: every admin of Recruitment). */
    public static function pelamar(Applicant $a): void
    {
        $a->loadMissing('form');
        $to = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->where('is_active', true)->get()->filter(fn (User $u) => $u->canEdit('recruitment'))->values();
        $posisi = $a->jawaban('posisi') ?? $a->form?->posisi ?? $a->form?->title;
        if ($to->isNotEmpty()) {
            Notification::send($to, new PengajuanMasuk('pelamar', "pelamar:{$a->id}", 'Pelamar Baru',
                $a->nama().' melamar '.$posisi.($a->form && $a->form->title !== $posisi ? " ({$a->form->title})" : ''),
                "/recruitment/pelamar?lihat={$a->id}", ['id' => null, 'nrp' => null, 'name' => $a->nama()]));
        }
    }

    /** A plan revision from an account without approval: for the approvers of that location. */
    public static function mppRevisi(MppRevisi $r): void
    {
        $r->loadMissing(['plan.workLocation', 'pembuat']);
        $locationId = $r->plan->work_location_id;
        $to = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->id !== $r->created_by && $u->canEdit('mpp-approve')
                && (($ids = AccessScope::workLocationIds($u)) === null || in_array($locationId, $ids, true)))
            ->values();
        if ($to->isNotEmpty()) {
            Notification::send($to, new PengajuanMasuk('mpp', "mpp:{$r->id}", 'Revisi Man Power Planning',
                "{$r->plan->workLocation->name} · {$r->plan->label()}: ".count($r->perubahan).' jabatan diubah oleh '.($r->pembuat?->name ?? '-'),
                "/manpower/mpp?lokasi={$locationId}&periode={$r->plan->kode()}", ['id' => null, 'nrp' => null, 'name' => $r->pembuat?->name]));
        }
    }

    /** The decision, for whoever sent the revision. */
    public static function mppHasil(MppRevisi $r): void
    {
        $r->loadMissing(['plan.workLocation', 'penyetuju', 'pembuat']);
        if (! $r->pembuat || $r->pembuat->id === $r->verified_by) {
            return;
        }
        $setuju = $r->status === 'disetujui';
        $r->pembuat->notify(new PengajuanMasuk('mpp-hasil', "mpp-hasil:{$r->id}", 'Revisi MPP '.($setuju ? 'disetujui' : 'ditolak'),
            "{$r->plan->workLocation->name} · {$r->plan->label()} oleh ".($r->penyetuju?->name ?? '-').($r->catatan_verifikasi ? " — {$r->catatan_verifikasi}" : ''),
            "/manpower/mpp?lokasi={$r->plan->work_location_id}&periode={$r->plan->kode()}", ['id' => null, 'nrp' => null, 'name' => $r->penyetuju?->name]));
    }

    /** HR decided, or the employee withdrew it: nothing left to do for anyone. */
    public static function selesai(string $ref): void
    {
        DatabaseNotification::where('ref', $ref)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Active admins with "ubah" on $menu whose locations include the employee's. */
    public static function recipients(string $menu, Karyawan $k): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->canEdit($menu) && (! $u->hasLocationScope()
                || in_array($k->work_location_id, array_map('intval', $u->work_location_ids ?? []), true)
                || in_array($k->sub_location_id, array_map('intval', $u->sub_location_ids ?? []), true)))
            ->values();
    }

    private static function send(string $jenis, string $ref, string $menu, ?Karyawan $k, string $judul, string $isi, string $url): void
    {
        if (! $k) {
            return;
        }
        $to = self::recipients($menu, $k);
        if ($to->isNotEmpty()) {
            Notification::send($to, new PengajuanMasuk($jenis, $ref, $judul, "{$k->name} ({$k->nrp}) {$isi}", $url, ['id' => $k->id, 'nrp' => $k->nrp, 'name' => $k->name]));
        }
    }
}
