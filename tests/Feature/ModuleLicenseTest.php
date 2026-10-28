<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\License\License;
use App\Support\Modules;
use App\Support\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Modules per license: Core always, Talent and LMS only when bought; the demo service has them all. */
class ModuleLicenseTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($kp));
        License::useKeyForTesting(base64_encode(sodium_crypto_sign_publickey($kp)));
        $this->path = tempnam(sys_get_temp_dir(), 'lic');
        @unlink($this->path);
        config(['eolas.license_path' => $this->path]);
        // A Talent page, before Fase 2 brings the real ones.
        Modules::assignMenusForTesting(['kpi' => 'talent']);
        Route::middleware(['web', 'auth', 'module:talent'])->get('/_test/talent', fn () => 'kpi');
    }

    protected function tearDown(): void
    {
        License::useKeyForTesting(null);
        Modules::assignMenusForTesting([]);
        @unlink($this->path);
        parent::tearDown();
    }

    private function install(?array $modules): void
    {
        $payload = ['license_id' => 'EOL-TEST', 'company' => 'PT Taylor Mining', 'tier' => 'starter', 'max_employees' => 100,
            'support_until' => now()->addYear()->toDateString(), 'issued_at' => now()->toDateString()];
        if ($modules !== null) {
            $payload['modules'] = $modules;
        }
        file_put_contents($this->path, License::sign($payload, $this->secret));
        License::forget();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_the_demo_service_has_every_module(): void
    {
        $this->assertSame(['core', 'talent', 'lms'], Modules::active());
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true])->get('/_test/talent')->assertOk();
        $this->assertTrue($admin->canView('kpi'));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('modules', ['core', 'talent', 'lms']));
    }

    public function test_a_license_without_modules_is_core_only_and_hides_the_add_ons(): void
    {
        config(['eolas.edition' => 'client']);
        $this->install(null);
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true]);

        $this->assertSame(['core'], Modules::active());
        $this->assertFalse($admin->canView('kpi'), 'an add-on menu is closed even to the Super Admin');
        $this->assertTrue($admin->canEdit('employees'));
        $this->get('/_test/talent')->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('modules', ['core']));
        $this->get('/settings/license')->assertInertia(fn (Assert $p) => $p->where('license.modules', [['key' => 'core', 'label' => Modules::LABELS['core']]]));
    }

    public function test_a_license_with_talent_opens_its_pages(): void
    {
        config(['eolas.edition' => 'client']);
        // Unknown names are ignored and Core is always there.
        $this->install(['talent', 'payroll-x']);
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true]);

        $this->assertSame(['core', 'talent'], Modules::active());
        $this->assertTrue($admin->canView('kpi'));
        $this->get('/_test/talent')->assertOk();
        $this->get('/settings/license')->assertInertia(fn (Assert $p) => $p->where('license.modules.1.key', 'talent'));
    }

    public function test_an_invalid_license_leaves_core_only(): void
    {
        config(['eolas.edition' => 'client']);
        file_put_contents($this->path, '{"payload":"x","signature":"y"}');
        License::forget();

        $this->assertSame(['core'], Modules::active());
    }

    public function test_the_issue_command_writes_the_modules(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'key');
        $out = tempnam(sys_get_temp_dir(), 'out');
        file_put_contents($key, $this->secret);

        $this->artisan('eolas:license:issue', ['company' => 'PT X', '--modules' => 'talent,core', '--key' => $key, '--out' => $out])->assertSuccessful();
        $checked = License::check(file_get_contents($out));
        $this->assertTrue($checked['valid']);
        $this->assertSame(['core', 'talent'], $checked['modules']);

        $this->artisan('eolas:license:issue', ['company' => 'PT X', '--modules' => 'core,kpi', '--key' => $key, '--out' => $out])->assertFailed();
        @unlink($key);
        @unlink($out);
    }
}
