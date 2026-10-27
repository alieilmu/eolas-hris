<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionDemoWorkspace;
use App\Models\TrialAccount;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * Keeps the demo server within bounds (scheduled every hour):
 *  - a demo ended more than eolas.demo_keep_days ago loses its workspace (database and files);
 *    the request stays, as a lead, and the Super Admin can open it again;
 *  - requests in the waiting line are prepared, oldest first, as places free up.
 */
class PruneWorkspaces extends Command
{
    protected $signature = 'eolas:prune-workspaces {--dry-run : hanya tampilkan, tidak mengubah apa pun}';

    protected $description = 'Menghapus workspace demo yang sudah lama berakhir dan memproses daftar tunggu';

    public function handle(): int
    {
        if (\App\Support\Edition::client()) {
            return self::SUCCESS; // no demo workspaces in a client installation
        }
        $dry = (bool) $this->option('dry-run');
        $before = now()->subDays((int) config('eolas.demo_keep_days', 7));

        $ended = TrialAccount::whereIn('status', ['active', 'inactive', 'failed'])
            ->whereNotNull('expires_at')->where('expires_at', '<', $before)->orderBy('id')->get();
        foreach ($ended as $account) {
            $this->line("Hapus workspace #{$account->id} {$account->company_name} (berakhir {$account->expires_at->toDateString()})");
            if (! $dry) {
                Tenant::drop(Tenant::databaseFor($account));
                $account->update(['status' => 'archived', 'database' => null]);
            }
        }

        $started = 0;
        if (config('eolas.demo_auto_approve')) {
            $free = max(0, (int) config('eolas.demo_max_active', 30) - TrialAccount::occupied());
            foreach (TrialAccount::where('status', 'waiting')->orderBy('id')->limit($free)->get() as $account) {
                $this->line("Siapkan workspace #{$account->id} {$account->company_name} (daftar tunggu)");
                if (! $dry) {
                    $account->update(['status' => 'provisioning', 'approved_at' => now()]);
                    ProvisionDemoWorkspace::dispatch($account->id);
                }
                $started++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."{$ended->count()} workspace dihapus, {$started} dari daftar tunggu disiapkan.");

        return self::SUCCESS;
    }
}
