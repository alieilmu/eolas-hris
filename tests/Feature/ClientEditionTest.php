<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\License\License;
use App\Support\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The client edition: no demo pages, a signed license, and its cap on active employees. */
class ClientEditionTest extends TestCase
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
        config(['eolas.edition' => 'client', 'eolas.license_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        License::useKeyForTesting(null);
        @unlink($this->path);
        parent::tearDown();
    }

    private function license(array $over = [], ?string $secret = null): string
    {
        return License::sign(['license_id' => 'EOL-TEST', 'company' => 'PT Taylor Mining', 'tier' => 'starter', 'max_employees' => 100,
            'support_until' => now()->addYear()->toDateString(), 'issued_at' => now()->toDateString(), ...$over], $secret ?? $this->secret);
    }

    private function upload(string $contents): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($f, $contents);

        return new UploadedFile($f, 'license.json', 'application/json', null, true);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_no_demo_pages_and_no_landing_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/demo')->assertNotFound();
        $this->post('/demo', [])->assertNotFound();
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('client_edition', true)->where('edition', 'client'));
        file_put_contents($this->path, $this->license());
        $this->actingAs($this->admin())->withSession([TwoFactor::SESSION_PASSED => true])->get('/settings/trial-accounts')->assertNotFound();
    }

    public function test_without_a_valid_license_only_the_license_page_opens(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([TwoFactor::SESSION_PASSED => true]);
        $this->get('/dashboard')->assertRedirect('/settings/license');
        $this->get('/settings/license')->assertInertia(fn (Assert $p) => $p->component('Settings/License')->where('license.valid', false)->where('can_upload', true));

        // Signed with another key, or changed after signing: refused.
        $other = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->post('/settings/license', ['file' => $this->upload($this->license([], $other))])->assertSessionHasErrors('file');
        $tampered = json_decode($this->license(), true);
        $tampered['payload'] = base64_encode(str_replace('100', '9999', base64_decode($tampered['payload'])));
        $this->post('/settings/license', ['file' => $this->upload(json_encode($tampered))])->assertSessionHasErrors('file');
        $this->get('/dashboard')->assertRedirect('/settings/license');

        $this->post('/settings/license', ['file' => $this->upload($this->license())])->assertRedirect('/settings/license');
        License::forget();
        $this->get('/dashboard')->assertOk();
        $this->get('/settings/license')->assertInertia(fn (Assert $p) => $p->where('license.valid', true)->where('license.company', 'PT Taylor Mining')
            ->where('license.max_employees', 100)->where('license.supported', true));
    }

    public function test_the_license_caps_active_employees(): void
    {
        $this->seed();
        $active = Employee::withoutGlobalScopes()->where('status', '!=', Employee::STATUS_TERMINATED)->count();
        file_put_contents($this->path, $this->license(['max_employees' => $active]));
        License::forget();
        $base = Employee::withoutGlobalScopes()->firstOrFail()->only(['position_id', 'work_location_id', 'job_level_id']);

        try {
            Employee::create($base + ['employee_number' => 'NEW-1', 'name' => 'Karyawan Baru', 'status' => Employee::STATUS_ACTIVE]);
            $this->fail('An employee past the license cap was created.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("Batas lisensi {$active}", $e->errors()['employee'][0]);
        }

        file_put_contents($this->path, $this->license(['max_employees' => $active + 1]));
        License::forget();
        Employee::create($base + ['employee_number' => 'NEW-2', 'name' => 'Karyawan Baru', 'status' => Employee::STATUS_ACTIVE]);
        $this->assertSame($active + 1, License::activeEmployees());
    }

    public function test_issuing_and_checking_a_license(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($key, $this->secret);
        $out = tempnam(sys_get_temp_dir(), 'out');
        $this->artisan('eolas:license:issue', ['company' => 'PT Contoh', '--tier' => 'growth', '--support-until' => '2027-10-31', '--key' => $key, '--out' => $out])->assertSuccessful();
        $l = License::check(file_get_contents($out));
        $this->assertTrue($l['valid']);
        $this->assertSame(['PT Contoh', 'growth', 300, '2027-10-31'], [$l['company'], $l['tier'], $l['max_employees'], $l['support_until']]);
        $this->artisan('eolas:license:issue', ['company' => 'PT X', '--key' => '/tidak/ada'])->assertFailed();

        rename($out, $this->path);
        License::forget();
        $this->artisan('eolas:license:check --for-update')->assertSuccessful();
        file_put_contents($this->path, $this->license(['support_until' => now()->subDay()->toDateString()]));
        License::forget();
        $this->artisan('eolas:license:check')->assertSuccessful();
        $this->artisan('eolas:license:check --for-update')->assertFailed();
        @unlink($key);
    }

    public function test_the_first_setup_seeds_and_makes_the_super_admin(): void
    {
        putenv('EOLAS_ADMIN_PASSWORD=pendek');
        $this->artisan('eolas:client:setup', ['--company' => 'PT Taylor', '--admin-name' => 'Admin HR', '--admin-email' => 'hr@taylor.co.id'])->assertFailed();
        putenv('EOLAS_ADMIN_PASSWORD=rahasia-sekali-123');
        $this->artisan('eolas:client:setup', ['--company' => 'PT Taylor', '--admin-name' => 'Admin HR', '--admin-email' => 'HR@taylor.co.id'])->assertSuccessful();
        $admin = User::where('email', 'hr@taylor.co.id')->sole();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('rahasia-sekali-123', $admin->password));
        $this->assertSame('PT Taylor', \App\Models\Branding::query()->value('company_name'));
        $this->assertSame('pending', \App\Models\WorkspaceSetup::query()->value('status'));
        // Again: no second seeding, the same account.
        $this->artisan('eolas:client:setup', ['--company' => 'PT Taylor Mining', '--admin-name' => 'Admin HR', '--admin-email' => 'hr@taylor.co.id'])->assertSuccessful();
        $this->assertSame(1, User::count());
        putenv('EOLAS_ADMIN_PASSWORD');
    }

    public function test_the_saas_edition_needs_no_license(): void
    {
        config(['eolas.edition' => 'saas']);
        $this->actingAs($this->admin())->withSession([TwoFactor::SESSION_PASSED => true])->get('/dashboard')->assertOk();
        $this->get('/settings/license')->assertNotFound();
    }
}
