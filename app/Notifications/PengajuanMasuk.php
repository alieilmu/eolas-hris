<?php

namespace App\Notifications;

use App\Notifications\Channels\PengajuanChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A pengajuan sent from the employee portal (update data, deklarasi, dinas, cuti, Cuti Besar),
 * for an admin who verifies it. Kept in the database and shown under the bell in the sidebar.
 */
class PengajuanMasuk extends Notification
{
    use Queueable;

    /**
     * @param  string  $jenis  update-data | deklarasi | dinas | cuti | cuti-besar
     * @param  string  $ref  "deklarasi:12": every copy is closed once HR has decided
     */
    public function __construct(
        public readonly string $jenis,
        public readonly string $ref,
        public readonly string $judul,
        public readonly string $isi,
        public readonly string $url,
        public readonly array $karyawan,
    ) {}

    public function via(object $notifiable): array
    {
        return [PengajuanChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return ['jenis' => $this->jenis, 'ref' => $this->ref, 'judul' => $this->judul, 'isi' => $this->isi, 'url' => $this->url, 'karyawan' => $this->karyawan];
    }
}
