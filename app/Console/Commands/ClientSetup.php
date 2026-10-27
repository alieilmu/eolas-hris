<?php

namespace App\Console\Commands;

use App\Models\Branding;
use App\Models\EmployeeCategory;
use App\Models\User;
use App\Support\Edition;
use Database\Seeders\EolasBlankSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * First start of a client installation (run by install.sh): the shared master data, the
 * company's name and its Super Admin, who then goes through the sesi awal. Safe to run
 * again: it seeds only an empty database and updates the Super Admin by email.
 */
class ClientSetup extends Command
{
    protected $signature = 'eolas:client:setup
        {--company= : nama perusahaan}
        {--admin-name= : nama Super Admin}
        {--admin-email= : email Super Admin}';

    protected $description = 'Menyiapkan instalasi klien: master data awal, nama perusahaan, akun Super Admin';

    public function handle(): int
    {
        if (! Edition::client()) {
            $this->error('Hanya untuk edisi klien (EOLAS_EDITION=client).');

            return self::FAILURE;
        }
        // The password comes from the environment, not the command line (process lists, shell history).
        $data = [
            'company' => (string) $this->option('company'),
            'name' => (string) $this->option('admin-name'),
            'email' => strtolower((string) $this->option('admin-email')),
            'password' => (string) env('EOLAS_ADMIN_PASSWORD'),
        ];
        $v = Validator::make($data, [
            'company' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:10'],
        ], ['password.required' => 'EOLAS_ADMIN_PASSWORD kosong.', 'password.min' => 'Password Super Admin minimal 10 karakter.']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        if (! EmployeeCategory::exists() && ! User::exists()) {
            $this->call('db:seed', ['--class' => EolasBlankSeeder::class, '--force' => true]);
        }
        Branding::query()->first()?->update(['company_name' => $data['company']]);
        Branding::forget();

        $admin = User::where('email', $data['email'])->first() ?? new User;
        $admin->forceFill([
            'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'must_change_password' => false,
        ])->save();
        $this->info("Siap: {$data['company']}, Super Admin {$data['email']}.");

        return self::SUCCESS;
    }
}
