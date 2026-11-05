<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPush;
use App\Models\User;
use App\Support\Push\PushCategories;
use App\Support\Push\WebPush;
use Illuminate\Notifications\Notification;

/**
 * Also on the phone: a notification the account wants pushed (PushCategories) goes to each of
 * its phones through the queue, so a slow push service never holds up the pengajuan.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! WebPush::enabled() || ! PushCategories::wants($notifiable, $notification->type ?? null)
            || ! $notifiable->pushSubscriptions()->exists()) {
            return;
        }
        $data = $notification->toArray($notifiable);
        SendPush::dispatch($notifiable->id, [
            'title' => $data['title'],
            'body' => $data['body'],
            'url' => $data['url'] ?? '/',
            // The same pengajuan again replaces its earlier notification on the phone.
            'tag' => $data['ref'] ?? null,
        ])->afterCommit();
    }
}
