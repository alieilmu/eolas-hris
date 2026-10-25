<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Access\Menu;
use Illuminate\Database\Seeder;

/**
 * Development logins only. Passwords come from .env (SEED_*_PASSWORD) and must
 * be changed before the app is used anywhere but a local machine.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@eolas.local', 'role' => User::ROLE_ADMIN, 'env' => 'SEED_ADMIN_PASSWORD', 'default' => 'admin123'],
            ['name' => 'Staff HR', 'email' => 'hr@eolas.local', 'role' => User::ROLE_HR, 'permissions' => Menu::all(), 'env' => 'SEED_HR_PASSWORD', 'default' => 'hr123'],
            ['name' => 'Viewer', 'email' => 'viewer@eolas.local', 'role' => User::ROLE_HR, 'permissions' => ['org-chart' => 'view'], 'env' => 'SEED_VIEWER_PASSWORD', 'default' => 'viewer123'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'role' => $u['role'],
                    // Settings → Akun & Akses: HR has every menu, Viewer only looks at the organisation chart.
                    'permissions' => $u['permissions'] ?? null,
                    'password' => env($u['env']) ?: $u['default'],
                ],
            );
        }
    }
}
