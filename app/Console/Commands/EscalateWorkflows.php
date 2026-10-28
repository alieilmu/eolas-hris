<?php

namespace App\Console\Commands;

use App\Models\TrialAccount;
use App\Support\Edition;
use App\Support\Tenancy\Tenant;
use App\Support\Workflow\Workflow;
use Illuminate\Console\Command;

/**
 * Hourly: approval steps past their time limit remind their approvers, then pass up to the
 * approvers' superiors (Workflow::escalate). In the hosted demo, every active workspace too.
 */
class EscalateWorkflows extends Command
{
    protected $signature = 'eolas:workflow:escalate';

    protected $description = 'Mengingatkan dan mengeskalasi langkah persetujuan yang lewat batas waktu';

    public function handle(): int
    {
        $done = Workflow::escalate();
        if (! Edition::client()) {
            foreach (TrialAccount::where('status', 'active')->whereNotNull('database')->get() as $account) {
                if ($account->expired()) {
                    continue;
                }
                Tenant::use($account->database);
                $more = Workflow::escalate();
                Tenant::central();
                $done = ['reminded' => $done['reminded'] + $more['reminded'], 'escalated' => $done['escalated'] + $more['escalated']];
            }
        }
        $this->info("{$done['reminded']} pengingat, {$done['escalated']} eskalasi.");

        return self::SUCCESS;
    }
}
