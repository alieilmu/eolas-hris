<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Branding;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserPasskey;
use App\Support\Security\AppLock;
use App\Support\Security\Passkeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Fase 1 week 6: the portal as an app on the phone — manifest, long sign-in, PIN and fingerprint / face. */
class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_icons_and_service_worker_follow_white_label(): void
    {
        Branding::current()->fill(['app_name' => 'Sinar HR', 'company_name' => 'PT Sinar', 'brand_color' => '#1f5fa8'])->save();
        Branding::forget();

        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame(['Sinar HR — PT Sinar', 'Sinar HR', '#1f5fa8', 'standalone'], [$m['name'], $m['short_name'], $m['theme_color'], $m['display']]);
        $this->assertSame(['any', 'maskable', 'any', 'maskable'], array_column($m['icons'], 'purpose'));

        foreach (['192.png' => 192, '512-maskable.png' => 512, '180.png' => 180] as $file => $size) {
            $png = $this->get("/app-icon/{$file}")->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
            $this->assertSame([$size, $size], array_slice(getimagesizefromstring($png), 0, 2));
        }
        $this->get('/app-icon/100.png')->assertNotFound();

        $sw = $this->get('/sw.js')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertStringContainsString('application/javascript', $sw->headers->get('Content-Type'));
        $this->assertStringContainsString("const OFFLINE = '/offline'", $sw->getContent());
        $this->get('/offline')->assertOk()->assertSee('Tidak ada koneksi internet')->assertSee('Sinar HR');
        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee('<meta name="theme-color" content="#1f5fa8">', false);
    }

    public function test_a_sign_in_from_the_app_lasts_and_is_opened_again_with_the_pin(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR]);
        AppLock::setPin($user, '482913');

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'app' => '1'])->assertSessionHasNoErrors();
        $login->assertCookie(AppLock::COOKIE);
        $recaller = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName());
        $this->assertNotNull($recaller, 'remembered');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $recaller->getExpiresTime(), 120);

        // The short session ran out; the long sign-in brings the account back, locked.
        $this->reopen($user);
        $this->get('/core-hr/employees')->assertRedirect('/app-lock');
        $this->get('/app-lock')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/AppUnlock')->where('pin', true)->where('passkey', false));
        $this->postJson('/account/app/pin', [])->assertStatus(423);

        $this->post('/app-lock/pin', ['pin' => '000000'])->assertSessionHasErrors('pin');
        $this->post('/app-lock/pin', ['pin' => '482913'])->assertRedirect('/core-hr/employees');
        // The next request: the account comes from the session now (the test keeps one guard across requests).
        $this->app['auth']->forgetGuards();
        $this->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.app_pin_failed', 'user_id' => $user->id]);

        // Opened again from the home screen within the session: the app asks for the lock.
        $this->post('/app-lock/lock')->assertRedirect('/app-lock');
        $this->get('/dashboard')->assertRedirect('/app-lock');
    }

    public function test_five_wrong_pins_sign_out_and_no_pin_ends_the_long_sign_in(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR]);
        AppLock::setPin($user, '482913');
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'app' => '1']);
        $this->reopen($user);
        $this->get('/dashboard')->assertRedirect('/app-lock');
        foreach (range(1, 4) as $_) {
            $this->post('/app-lock/pin', ['pin' => '111111'])->assertSessionHasErrors('pin');
        }
        $this->post('/app-lock/pin', ['pin' => '111111'])->assertRedirect('/login');
        $this->assertGuest();

        $other = User::factory()->create(['role' => User::ROLE_HR]);
        $this->post('/login', ['email' => $other->email, 'password' => 'password', 'app' => '1']);
        $this->reopen($other);
        $this->get('/dashboard')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_without_the_setting_the_app_signs_in_like_the_browser(): void
    {
        Branding::current()->fill(['mobile_session_days' => 0])->save();
        $user = User::factory()->create(['role' => User::ROLE_HR]);
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'app' => '1']);
        $login->assertCookieMissing(AppLock::COOKIE);
        $this->assertNull(collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName()));

        // The Super Admin sets it in White Label.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post('/settings/branding', ['brand_color' => '#15803d', 'accent_color' => '#86efac', 'mobile_session_days' => 7])->assertSessionHasNoErrors();
        $this->assertSame(7, (int) Branding::query()->value('mobile_session_days'));
    }

    public function test_the_pin_is_set_with_the_password_and_not_an_easy_one(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR]);
        $this->actingAs($user)->get('/account/app')->assertInertia(fn (Assert $p) => $p->component('Account/AppSecurity')->where('pin', false)->where('days', 30));
        $set = fn (array $d) => $this->post('/account/app/pin', $d + ['password' => 'password']);
        $set(['pin' => '123456', 'pin_confirmation' => '123456'])->assertSessionHasErrors('pin');
        $set(['pin' => '777777', 'pin_confirmation' => '777777'])->assertSessionHasErrors('pin');
        $set(['pin' => '48291', 'pin_confirmation' => '48291'])->assertSessionHasErrors('pin');
        $set(['pin' => '482913', 'pin_confirmation' => '482914'])->assertSessionHasErrors('pin');
        $set(['pin' => '482913', 'pin_confirmation' => '482913', 'password' => 'salah'])->assertSessionHasErrors('password');
        $set(['pin' => '482913', 'pin_confirmation' => '482913'])->assertSessionHasNoErrors();
        $this->assertTrue(AppLock::hasPin($user->fresh()));
        $this->assertArrayNotHasKey('app_pin', $user->fresh()->toArray());
    }

    public function test_the_phones_fingerprint_opens_the_app(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR]);
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $pem = openssl_pkey_get_details($key)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));

        // Registered from Akun → Keamanan Aplikasi.
        $this->actingAs($user);
        $options = $this->getJson('/account/app/passkey')->assertOk()->json();
        $this->assertSame(['localhost', 'platform', [-7, -257]], [$options['rp']['id'], $options['authenticatorSelection']['authenticatorAttachment'], array_column($options['pubKeyCredParams'], 'alg')]);
        $this->post('/account/app/passkey', [
            'id' => 'cred-1', 'clientDataJSON' => $this->client('webauthn.create', $options['challenge']),
            'publicKey' => Passkeys::b64url($der), 'algorithm' => -7, 'name' => 'Pixel 8',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Pixel 8', UserPasskey::sole()->name);

        // The app was locked: the phone signs the server's challenge.
        $this->withSession([AppLock::SESSION_DEVICE => true, AppLock::SESSION_LOCKED => true]);
        $this->get('/dashboard')->assertRedirect('/app-lock');
        $answer = function (int $count, ?string $origin = null) use ($key) {
            $challenge = $this->getJson('/app-lock/passkey')->assertOk()->json('challenge');
            $client = $this->client('webauthn.get', $challenge, $origin);
            $auth = hash('sha256', 'localhost', true).chr(0x05).pack('N', $count);
            openssl_sign($auth.hash('sha256', Passkeys::unb64url($client), true), $signature, $key, OPENSSL_ALGO_SHA256);

            return $this->post('/app-lock/passkey', ['id' => 'cred-1', 'clientDataJSON' => $client, 'authenticatorData' => Passkeys::b64url($auth), 'signature' => Passkeys::b64url($signature)]);
        };
        $answer(1, 'https://evil.example')->assertSessionHasErrors('passkey');
        $answer(3)->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertSame(3, UserPasskey::sole()->sign_count);

        // A key whose count does not go up is a copy.
        AppLock::lock(request());
        $this->withSession([AppLock::SESSION_LOCKED => true]);
        $answer(2)->assertSessionHasErrors('passkey');
    }

    public function test_beranda_shows_today_the_newest_slip_and_the_install_props(): void
    {
        $this->seed();
        $employee = Employee::where('status', Employee::STATUS_ACTIVE)->whereNotNull('position_id')->orderBy('id')->firstOrFail();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $employee->id]);
        AttendanceLog::create(['employee_id' => $employee->id, 'pin' => 'x', 'punched_at' => today()->setTime(7, 4), 'source' => 'manual']);

        $this->actingAs($user)->withSession([AppLock::SESSION_DEVICE => true])->get('/self-service')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('SelfService/Home')
            ->where('attendance_today.in', '07:04')->where('attendance_today.out', null)
            ->has('latest_payslip')
            ->where('auth.app', ['pin' => false, 'passkey' => false, 'days' => 30]));
    }

    /** A new request after the short session expired: only the long sign-in's cookies are left. */
    private function reopen(User $user): void
    {
        $user->refresh();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookies([
            Auth::guard('web')->getRecallerName() => $user->id.'|'.$user->getRememberToken().'|'.$user->getAuthPassword(),
            AppLock::COOKIE => '1',
        ]);
    }

    private function client(string $type, string $challenge, ?string $origin = null): string
    {
        // The test requests go to APP_URL (http://localhost:8000).
        $origin ??= rtrim((string) config('app.url'), '/');

        return Passkeys::b64url(json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin]));
    }
}
