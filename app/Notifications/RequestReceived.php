<?php

namespace App\Notifications;

use App\Notifications\Channels\RequestChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A pengajuan sent from the employee portal (update data, deklarasi, dinas, cuti, Cuti Besar),
 * for an admin who verifies it. Kept in the database and shown under the bell in the sidebar.
 */
class RequestReceived extends Notification
{
    use Queueable;

    /**
     * @param  string  $jenis  update-data | deklarasi | dinas | cuti | cuti-besar
     * @param  string  $ref  "deklarasi:12": every copy is closed once HR has decided
     */
    public function __construct(
        public readonly string $type,
        public readonly string $ref,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly array $employee,
    ) {}

    public function via(object $notifiable): array
    {
        return [RequestChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'ref' => $this->ref, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'employee' => $this->employee];
    }
}
