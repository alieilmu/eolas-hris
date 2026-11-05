<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\RequestReceived;
use App\Support\Push\WebPush;
use App\Support\Security\Passkeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fase 1 week 9: notifications pushed to the phone (Web Push, VAPID): the phone subscribing,
 * the message encrypted so only it can read it and signed with the installation's key, the
 * kinds an account turns off, a phone gone from its push service, and push turned off.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123:APA91b-test';

    private User $user;

    /** The phone's own key pair and auth secret, as a browser makes them. */
    private \OpenSSLAsymmetricKey $phone;

    private string $phonePublic;

    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['eolas.push.key_path' => sys_get_temp_dir().'/eolas-vapid-'.uniqid().'.json', 'eolas.push.enabled' => true, 'app.url' => 'https://hris.test']);
        $employee = Employee::where('status', Employee::STATUS_ACTIVE)->orderBy('id')->firstOrFail();
        $this->user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => $employee->id]);
        $this->phone = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->phone)['ec'];
        $this->phonePublic = "\x04".str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
        $this->auth = random_bytes(16);
    }

    protected function tearDown(): void
    {
        @unlink((string) config('eolas.push.key_path'));
        parent::tearDown();
    }

    private function subscribe(?User $as = null, string $endpoint = self::ENDPOINT)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/account/notifications/subscription', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => Passkeys::b64url($this->phonePublic), 'auth' => Passkeys::b64url($this->auth)], 'name' => 'Pixel 8',
        ]);
    }

    /** What the phone does with a push: RFC 8291 backwards, with its private key. */
    private function decrypt(string $body): array
    {
        $salt = substr($body, 0, 16);
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));
        $server = substr($body, 21, 65);
        $cipher = substr($body, 86, -16);
        $tag = substr($body, -16);
        $shared = openssl_pkey_derive(WebPush::publicPem($server), $this->phone, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00".$this->phonePublic.$server, $this->auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $this->assertNotFalse($plain, 'the phone cannot decrypt the push');
        $this->assertSame("\x02", substr($plain, -1));

        return json_decode(substr($plain, 0, -1), true);
    }

    /** The push service checks the VAPID JWT with the key in "k=". */
    private function assertVapid(string $authorization, string $audience): void
    {
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $authorization);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $authorization, $m);
        $this->assertSame(WebPush::publicKey(), $m[4]);
        $claims = json_decode(Passkeys::unb64url($m[2]), true);
        $this->assertSame([$audience, 'https://hris.test'], [$claims['aud'], $claims['sub']]);
        $this->assertGreaterThan(time(), $claims['exp']);
        // r || s back to DER for OpenSSL.
        $raw = Passkeys::unb64url($m[3]);
        $int = fn (string $v) => (ord(($v = ltrim($v, "\x00") ?: "\x00")[0]) & 0x80 ? "\x00".$v : $v);
        [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
        $der = "\x30".chr(strlen($r) + strlen($s) + 4)."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $this->assertSame(1, openssl_verify("{$m[1]}.{$m[2]}", $der, WebPush::publicPem(Passkeys::unb64url($m[4])), OPENSSL_ALGO_SHA256));
    }

    public function test_a_notification_reaches_the_phone_encrypted_and_signed(): void
    {
        $key = null;
        $this->actingAs($this->user)->get('/account/notifications')->assertInertia(function (Assert $p) use (&$key) {
            $p->component('Account/Notifications')->where('enabled', true)->has('devices', 0)
                ->where('preferences', ['requests' => true, 'approvals' => true, 'payslip' => true, 'certification' => true]);
            $key = $p->toArray()['props']['public_key'];
        });
        $this->assertSame(65, strlen(Passkeys::unb64url($key)));
        // The same key on every page (to keep the phone's subscription fresh) and for every workspace.
        $this->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('auth.push', $key));

        $this->subscribe()->assertOk();
        $this->subscribe()->assertOk();
        $this->assertSame(1, PushSubscription::count());

        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $this->user->notify(new RequestReceived('approval-result', 'workflow-result:9', 'Cuti disetujui', 'Cuti Tahunan 2 hari · oleh Kepala Uji', '/approvals?tab=mine', []));

        // In the bell, and on the phone.
        $this->assertSame(1, $this->user->unreadNotifications()->count());
        $this->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('auth.notifications', 1));
        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            $this->assertSame(self::ENDPOINT, $r->url());
            $this->assertSame(['aes128gcm', '86400'], [$r->header('Content-Encoding')[0], $r->header('TTL')[0]]);
            $this->assertVapid($r->header('Authorization')[0], 'https://fcm.googleapis.com');
            $this->assertSame(['title' => 'Cuti disetujui', 'body' => 'Cuti Tahunan 2 hari · oleh Kepala Uji', 'url' => '/approvals?tab=mine', 'tag' => 'workflow-result:9'], $this->decrypt($r->body()));

            return true;
        });
        $this->assertNotNull(PushSubscription::sole()->last_used_at);

        // The service worker shows it and opens its page.
        $this->get('/sw.js')->assertOk()->assertSee("addEventListener('push'", false)->assertSee('notificationclick', false);
    }

    public function test_only_known_push_services_and_valid_keys_are_taken_and_a_phone_follows_its_account(): void
    {
        $this->subscribe(endpoint: 'https://evil.example.com/push/1')->assertStatus(422)->assertJsonValidationErrorFor('endpoint');
        $this->subscribe(endpoint: 'http://fcm.googleapis.com/fcm/send/x')->assertStatus(422);
        $this->subscribe(endpoint: 'https://fcm.googleapis.com.evil.com/x')->assertStatus(422);
        $this->actingAs($this->user)->postJson('/account/notifications/subscription', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => Passkeys::b64url(random_bytes(65)), 'auth' => 'xyz']])
            ->assertStatus(422);
        $this->assertSame(0, PushSubscription::count());
        foreach (['https://web.push.apple.com/QGx1', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://wns2-db5p.notify.windows.com/w/?token=x'] as $ok) {
            $this->assertTrue(WebPush::allowedEndpoint($ok), $ok);
        }

        // Another account signs in on the same phone: the phone is theirs now.
        $this->subscribe()->assertOk();
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'employee_id' => Employee::where('id', '!=', $this->user->employee_id)->value('id')]);
        $this->subscribe($other)->assertOk();
        $this->assertSame($other->id, PushSubscription::sole()->user_id);

        // Turned off on the phone; someone else cannot remove it.
        $this->actingAs($this->user)->deleteJson('/account/notifications/subscription', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($other)->deleteJson('/account/notifications/subscription', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_kinds_turned_off_stay_in_the_bell_only(): void
    {
        $this->subscribe()->assertOk();
        $this->actingAs($this->user)->put('/account/notifications', ['preferences' => ['requests' => true, 'approvals' => true, 'payslip' => false, 'certification' => false]])
            ->assertSessionHasNoErrors();
        Http::fake(['*' => Http::response('', 201)]);

        $this->user->notify(new RequestReceived('payslip', 'payslip:1', 'Slip gaji terbit', 'Slip gaji Oktober 2026 sudah bisa dibuka.', '/self-service/payslips', []));
        $this->user->notify(new RequestReceived('certification-own', 'certification-own:1:30', 'Sertifikat Anda akan habis', 'SIMPER habis dalam 30 hari', '/self-service/certifications', []));
        Http::assertSentCount(0);
        $this->user->notify(new RequestReceived('approval', 'workflow:3', 'Menunggu persetujuan Anda: Cuti', '', '/approvals', []));
        Http::assertSentCount(1);
        $this->assertSame(3, $this->user->unreadNotifications()->count());
    }

    public function test_a_phone_gone_from_its_push_service_is_removed_and_the_test_message_says_so(): void
    {
        $this->subscribe()->assertOk();
        Http::fake(['*' => Http::sequence()->push('', 201)->push('', 500)->push('', 410)->pushFailedConnection()]);
        $this->actingAs($this->user)->postJson('/account/notifications/test')->assertOk()->assertJson(['sent' => 1, 'total' => 1]);
        $this->postJson('/account/notifications/test')->assertOk()->assertJson(['sent' => 0, 'total' => 1]);
        $this->assertSame(1, PushSubscription::sole()->failures);
        $this->postJson('/account/notifications/test')->assertOk()->assertJson(['sent' => 0]);
        $this->assertSame(0, PushSubscription::count());

        // No internet from the server: the notification is still saved, nothing fails.
        $this->subscribe()->assertOk();
        $this->user->notify(new RequestReceived('approval', 'workflow:4', 'Menunggu persetujuan Anda', '', '/approvals', []));
        $this->assertSame(1, $this->user->unreadNotifications()->count());
        $this->assertSame(1, PushSubscription::sole()->failures);
    }

    public function test_push_turned_off_on_the_server_keeps_the_bell(): void
    {
        $this->subscribe()->assertOk();
        config(['eolas.push.enabled' => false]);
        Http::fake();
        $this->actingAs($this->user)->get('/account/notifications')->assertInertia(fn (Assert $p) => $p->where('enabled', false)->where('public_key', null));
        $this->get('/self-service')->assertInertia(fn (Assert $p) => $p->where('auth.push', null));
        $this->subscribe()->assertNotFound();
        $this->user->notify(new RequestReceived('approval', 'workflow:5', 'Menunggu persetujuan Anda', '', '/approvals', []));
        Http::assertNothingSent();
        $this->assertSame(1, $this->user->unreadNotifications()->count());
    }
}
