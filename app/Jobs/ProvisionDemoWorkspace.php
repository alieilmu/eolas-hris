<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;

/** Runs eolas:workspace for a demo account in its own PHP process (see ProvisionWorkspace). */
class ProvisionDemoWorkspace implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $accountId) {}

    public function handle(): void
    {
        Process::path(base_path())->timeout($this->timeout)
            ->run([PHP_BINARY, 'artisan', 'eolas:workspace', (string) $this->accountId])
            ->throw();
    }
}
