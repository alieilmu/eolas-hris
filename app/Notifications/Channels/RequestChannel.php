<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/** The database channel, also filling the ref column (which pengajuan the notification is about). */
class RequestChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        return parent::buildPayload($notifiable, $notification) + ['ref' => $notification->ref ?? null];
    }
}
