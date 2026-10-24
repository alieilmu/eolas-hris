<?php

namespace App\Console\Commands;

use App\Models\Branding;
use App\Models\DemoAccount;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Database\Seeders\EolasDemoSeeder;
use Database\Seeders\EolasKosongSeeder;
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
        $account = DemoAccount::findOrFail((int) $this->argument('account'));
        $database = Tenant::databaseFor($account);
        $account->update(['status' => 'menyiapkan', 'catatan' => null]);

        try {
            Tenant::drop($database);
            Tenant::create($database);
            Tenant::use($database);

            $this->call('migrate', ['--force' => true]);
            $this->call('db:seed', ['--class' => $account->sumber_data === 'kosong' ? EolasKosongSeeder::class : EolasDemoSeeder::class, '--force' => true]);
            $this->accounts($account);
            Branding::query()->first()?->update(['company_name' => $account->perusahaan]);
        } catch (Throwable $e) {
            Tenant::central();
            $account->update(['status' => 'gagal', 'catatan' => Str::limit($e->getMessage(), 500)]);
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Tenant::central();
        $account->update([
            'status' => 'aktif',
            'database' => $database,
            'provisioned_at' => now(),
            'expires_at' => $account->expires_at ?? now()->addDays((int) config('eolas.demo_days', 14))->endOfDay(),
        ]);
        $this->info("Workspace {$database} siap ({$account->sumber_data}).");

        return self::SUCCESS;
    }

    /**
     * The requester becomes the Super Admin (taking over the seeded one, whose id the demo
     * records point at); the other seeded admins keep their roles with a random password
     * the Super Admin can reset in Settings → Akun & Akses.
     */
    private function accounts(DemoAccount $account): void
    {
        $hash = DB::connection('central')->table('demo_accounts')->where('id', $account->id)->value('password');
        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first() ?? new User(['role' => User::ROLE_ADMIN]);
        $admin->forceFill([
            'name' => $account->nama, 'email' => $account->email, 'password' => $hash,
            'is_active' => true, 'must_change_password' => false,
        ])->save();

        User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->whereKeyNot($admin->id)->get()
            ->each(fn (User $u) => $u->forceFill(['password' => Str::password(20)])->save());
    }
}
