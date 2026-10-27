<?php

namespace App\Console\Commands;

use App\Models\Branding;
use App\Models\TrialAccount;
use App\Support\Demo\Industry;
use App\Support\Demo\IndustrySkin;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Database\Seeders\EolasDemoSeeder;
use Database\Seeders\EolasBlankSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds (or rebuilds) the workspace of a demo account: a fresh database with every
 * migration, filled with the system's demo data or left empty for the user's own,
 * and the requester as its Super Admin. Run in its own process (ProvisionDemoWorkspace)
 * so no lookup cached in memory crosses from one workspace to another.
 */
class ProvisionWorkspace extends Command
{
    protected $signature = 'eolas:workspace {account : id akun demo}';

    protected $description = 'Menyiapkan / menyiapkan ulang workspace akun demo (database & data)';

    public function handle(): int
    {
        $account = TrialAccount::findOrFail((int) $this->argument('account'));
        $database = Tenant::databaseFor($account);
        $account->update(['status' => 'provisioning', 'notes' => null]);

        try {
            Tenant::drop($database);
            Tenant::create($database);
            Tenant::use($database);

            $this->call('migrate', ['--force' => true]);
            $this->call('db:seed', ['--class' => $account->data_source === 'blank' ? EolasBlankSeeder::class : EolasDemoSeeder::class, '--force' => true]);
            if ($account->data_source !== 'blank') {
                IndustrySkin::apply($account->industry ?? Industry::DEFAULT);
                // Certificates of the industry (positions are renamed by now).
                \App\Support\Demo\DemoCertifications::seed($account->industry ?? Industry::DEFAULT);
            }
            $this->accounts($account);
            Branding::query()->first()?->update(['company_name' => $account->company_name]);
        } catch (Throwable $e) {
            Tenant::central();
            $account->update(['status' => 'failed', 'notes' => Str::limit($e->getMessage(), 500)]);
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Tenant::central();
        $account->update([
            'status' => 'active',
            'database' => $database,
            'provisioned_at' => now(),
            'expires_at' => $account->expires_at ?? now()->addDays((int) config('eolas.demo_days', 14))->endOfDay(),
        ]);
        $this->info("Workspace {$database} siap ({$account->data_source}).");

        return self::SUCCESS;
    }

    /**
     * The requester becomes the Super Admin (taking over the seeded one, whose id the demo
     * records point at); the other seeded admins keep their roles with a random password
     * the Super Admin can reset in Administrasi → Pengguna & Akses.
     */
    private function accounts(TrialAccount $account): void
    {
        $hash = DB::connection('central')->table('trial_accounts')->where('id', $account->id)->value('password');
        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first() ?? new User(['role' => User::ROLE_ADMIN]);
        $admin->forceFill([
            'name' => $account->name, 'email' => $account->email, 'password' => $hash,
            'is_active' => true, 'must_change_password' => false,
        ])->save();

        User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->whereKeyNot($admin->id)->get()
            ->each(fn (User $u) => $u->forceFill(['password' => Str::password(20)])->save());
    }
}
