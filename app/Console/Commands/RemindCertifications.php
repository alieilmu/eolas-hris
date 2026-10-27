<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrialAccount;
use App\Support\Edition;
use App\Support\Notifications\RequestNotifier;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily: certificates 90, 60 and 30 days before they expire, and on the day, notify HR of the
 * employee's location and the employee, each step once (reminded_days). In the hosted demo
 * every active workspace gets its reminders too.
 */
class RemindCertifications extends Command
{
    protected $signature = 'eolas:certifications:remind';

    protected $description = 'Mengirim pengingat sertifikat/izin yang akan habis';

    public function handle(): int
    {
        $sent = $this->remind();
        if (! Edition::client()) {
            foreach (TrialAccount::where('status', 'active')->whereNotNull('database')->get() as $account) {
                if ($account->expired()) {
                    continue;
                }
                Tenant::use($account->database);
                $sent += $this->remind();
                Tenant::central();
            }
        }
        $this->info("{$sent} pengingat dikirim.");

        return self::SUCCESS;
    }

    private function remind(): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;
        $due = EmployeeCertification::withoutGlobalScopes()->with('employee', 'type')->whereNotNull('expires_at')
            ->where('expires_at', '>=', $today->toDateString())->where('expires_at', '<=', $today->addDays(max(EmployeeCertification::REMINDERS))->toDateString())
            ->whereHas('employee', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', Employee::STATUS_TERMINATED))->get();
        foreach ($due as $c) {
            $left = $c->daysLeft($today);
            // The nearest step reached and not sent yet (a newer one supersedes the older).
            $step = collect(EmployeeCertification::REMINDERS)->filter(fn ($d) => $left <= $d)->min();
            if ($step === null || ($c->reminded_days !== null && $c->reminded_days <= $step)) {
                continue;
            }
            RequestNotifier::certificationExpiring($c, $left);
            $c->forceFill(['reminded_days' => $step])->saveQuietly();
            $sent++;
        }

        return $sent;
    }
}
