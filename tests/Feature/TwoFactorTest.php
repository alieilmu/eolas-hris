<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Two-factor sign-in: setup, the code after the password, payroll pages needing it, and the audit log. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function payrollHr(): User
    {
        return User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['payroll-run' => 'edit']]);
    }

    private function code(string $secret, int $offset = 0): string
    {
        return (new Google2FA)->oathTotp($secret, (int) floor(time() / 30) + $offset);
    }

    public function test_payroll_pages_send_an_account_without_2fa_to_set_it_up(): void
    {
        $hr = $this->payrollHr();
        $this->actingAs($hr)->get('/compensation/payroll')->assertRedirect('/account/two-factor');
        $this->get('/account/two-factor')->assertInertia(fn (Assert $p) => $p->component('Account/TwoFactor')
            ->where('enabled', false)->where('required', true)->where('skippable', false));
        // Skipping is for demo workspaces only.
        $this->post('/account/two-factor/skip')->assertNotFound();
        // Accounts without the payroll menus are not asked.
        $this->assertFalse(TwoFactor::required(User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['employees' => 'edit']])));
    }

    public function test_setting_up_2fa_and_signing_in_with_it(): void
    {
        $hr = $this->payrollHr();
        $this->actingAs($hr)->post('/account/two-factor')->assertRedirect();
        $secret = session(TwoFactor::SESSION_PENDING);
        $this->assertNotNull($secret);
        $this->get('/account/two-factor')->assertInertia(fn (Assert $p) => $p->where('setup.secret', trim(chunk_split($secret, 4, ' ')))->has('setup.qr'));

        $this->post('/account/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/account/two-factor/confirm', ['code' => $this->code($secret)])->assertRedirect('/account/two-factor');
        $hr->refresh();
        $this->assertTrue($hr->hasTwoFactor());
        $this->assertSame($secret, $hr->two_factor_secret);
        $this->assertCount(8, $hr->two_factor_recovery_codes);
        $this->assertNotSame($secret, \DB::table('users')->where('id', $hr->id)->value('two_factor_secret')); // stored encrypted
        $this->get('/account/two-factor')->assertInertia(fn (Assert $p) => $p->where('enabled', true)->has('recovery_codes', 8));
        $this->get('/compensation/payroll')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'auth.two_factor_enabled')->where('user_id', $hr->id)->exists());

        // A new sign-in: the password, then the code before anything opens.
        $this->post('/logout');
        $this->post('/login', ['email' => $hr->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($hr);
        $this->get('/dashboard')->assertRedirect('/two-factor/challenge');
        $this->get('/compensation/payroll')->assertRedirect('/two-factor/challenge');
        $this->get('/two-factor/challenge')->assertInertia(fn (Assert $p) => $p->component('Auth/TwoFactorChallenge'));
        $this->post('/two-factor/challenge', ['code' => '111111'])->assertSessionHasErrors('code');
        $this->assertTrue(AuditLog::where('action', 'auth.two_factor_failed')->exists());
        // The code of the setup was used already; the next step's code works (± 30 s window), and only once.
        $next = $this->code($secret, 1);
        $this->post('/two-factor/challenge', ['code' => $next])->assertRedirect('/compensation/payroll');
        $this->get('/compensation/payroll')->assertOk();

        $this->post('/logout');
        $this->post('/login', ['email' => $hr->email, 'password' => 'password']);
        $this->post('/two-factor/challenge', ['code' => $next])->assertSessionHasErrors('code');
        $recovery = $hr->fresh()->two_factor_recovery_codes[0];
        $this->post('/two-factor/challenge', ['recovery_code' => strtoupper($recovery)])->assertRedirect();
        $this->get('/dashboard')->assertOk();
        $this->assertCount(7, $hr->fresh()->two_factor_recovery_codes);

        // With the payroll menus it stays on.
        $this->delete('/account/two-factor', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertTrue($hr->fresh()->hasTwoFactor());
    }

    public function test_an_account_without_payroll_may_turn_it_off_and_the_super_admin_resets_it(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['employees' => 'edit']]);
        $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['a']])->save();
        $this->actingAs($user)->withSession([TwoFactor::SESSION_PASSED => true]);
        $this->delete('/account/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->delete('/account/two-factor', ['password' => 'password'])->assertRedirect();
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $hr = $this->payrollHr();
        $hr->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true]);
        $this->get('/settings/users')->assertInertia(fn (Assert $p) => $p->where('admins', fn ($rows) => collect($rows)->firstWhere('id', $hr->id)['two_factor'] === true));
        $this->post("/settings/users/{$hr->id}/two-factor-reset")->assertRedirect();
        $this->assertFalse($hr->fresh()->hasTwoFactor());
        $this->actingAs($hr)->post("/settings/users/{$admin->id}/two-factor-reset")->assertForbidden();
    }

    public function test_the_audit_log_lists_sensitive_actions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->post('/login', ['email' => $admin->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->assertTrue(AuditLog::where('action', 'auth.login_failed')->where('user_id', $admin->id)->exists());

        // The Super Admin has the payroll menus: 2FA first.
        $admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->withSession([TwoFactor::SESSION_PASSED => true]);
        $this->get('/settings/audit-log')->assertInertia(fn (Assert $p) => $p->component('Settings/AuditLogs')
            ->where('logs.total', 2)->where('logs.data.0.action', 'auth.login')->where('logs.data.1.action', 'auth.login_failed'));
        $this->get('/settings/audit-log?action=auth.login_failed')->assertInertia(fn (Assert $p) => $p->where('logs.total', 1));
        $this->get('/settings/audit-log?group=Payroll')->assertInertia(fn (Assert $p) => $p->where('logs.total', 0));

        $this->actingAs($this->payrollHr())->get('/settings/audit-log')->assertForbidden();
    }
}
