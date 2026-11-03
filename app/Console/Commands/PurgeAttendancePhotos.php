<?php

namespace App\Console\Commands;

use App\Models\TrialAccount;
use App\Support\Edition;
use App\Support\Tenancy\Tenant;
use App\Support\TimeClock\MobileClock;
use Illuminate\Console\Command;

/**
 * Daily: selfies of punches from the phone past their retention (Absen HP, bawaan 90 hari)
 * are deleted; the punch itself stays (UU PDP). In the hosted demo, every active workspace too.
 */
class PurgeAttendancePhotos extends Command
{
    protected $signature = 'eolas:attendance:purge-photos';

    protected $description = 'Menghapus foto absen HP yang lewat masa simpan';

    public function handle(): int
    {
        $n = MobileClock::purgePhotos();
        if (! Edition::client()) {
            foreach (TrialAccount::where('status', 'active')->whereNotNull('database')->get() as $account) {
                if ($account->expired()) {
                    continue;
                }
                Tenant::use($account->database);
                $n += MobileClock::purgePhotos();
                Tenant::central();
            }
        }
        $this->info("{$n} foto dihapus.");

        return self::SUCCESS;
    }
}
