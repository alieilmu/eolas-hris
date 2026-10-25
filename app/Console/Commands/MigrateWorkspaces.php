<?php

namespace App\Console\Commands;

use App\Models\TrialAccount;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/** Runs the migrations of every demo workspace database (after the main one is migrated). */
class MigrateWorkspaces extends Command
{
    protected $signature = 'eolas:migrate-workspaces';

    protected $description = 'Menjalankan migration di semua database workspace demo';

    public function handle(): int
    {
        $failed = 0;
        foreach (TrialAccount::whereNotNull('database')->whereIn('status', ['active', 'inactive'])->get() as $account) {
            try {
                Tenant::use($account->database);
                $this->call('migrate', ['--force' => true]);
                $this->info("{$account->database}: ok");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$account->database}: {$e->getMessage()}");
            } finally {
                Tenant::central();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
