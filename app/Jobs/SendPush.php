<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\Push\WebPush;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one notification to every phone of an account, in the workspace it came from (the
 * queue lives in the main database). A push service that cannot be reached is not retried:
 * the notification is in the bell all the same.
 */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public ?string $database;

    public function __construct(public int $userId, public array $payload)
    {
        $this->database = Tenant::current();
    }

    public function handle(): void
    {
        $switch = $this->database !== null && Tenant::current() !== $this->database;
        if ($switch) {
            Tenant::use($this->database);
        }
        try {
            foreach (PushSubscription::where('user_id', $this->userId)->get() as $s) {
                rescue(fn () => WebPush::send($s, $this->payload), report: true);
            }
        } finally {
            if ($switch) {
                Tenant::central();
            }
        }
    }
}
