<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

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
            // PHP_BINARY is empty when the job runs inside the web server (sync queue).
            ->run([PHP_BINARY ?: ((new PhpExecutableFinder)->find(false) ?: 'php'), 'artisan', 'eolas:workspace', (string) $this->accountId])
            ->throw();
    }
}
