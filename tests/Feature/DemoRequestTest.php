<?php

namespace Tests\Feature;

use App\Jobs\ProvisionDemoWorkspace;
use App\Models\TrialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Pengajuan Akun Demo: captcha, honeypot, the cap on open workspaces and their removal. */
class DemoRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['eolas.demo_auto_approve' => true, 'eolas.demo_max_active' => 2, 'eolas.demo_keep_days' => 7]);
    }

    private function form(array $extra = []): array
    {
        static $n = 0;
        $n++;

        return [
            'name' => 'Budi', 'email' => "budi{$n}@contoh.co.id", 'company_name' => "PT Contoh {$n}",
            'data_source' => 'demo', 'industry' => 'mining',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'agree' => true,
            ...$extra,
        ];
    }

    private function account(string $status, ?string $expires = null): TrialAccount
    {
        static $n = 0;
        $n++;

        return TrialAccount::create([
            'code' => TrialAccount::newCode(), 'name' => 'X', 'email' => "x{$n}@contoh.co.id", 'password' => 'rahasia123',
            'company_name' => "PT X {$n}", 'data_source' => 'demo', 'status' => $status,
            'expires_at' => $expires, 'database' => $status === 'active' ? "hris_eloas_t{$n}" : null,
        ]);
    }

    public function test_a_request_starts_its_workspace(): void
    {
        $this->post('/demo', $this->form())->assertRedirect();

        $this->assertSame('provisioning', TrialAccount::sole()->status);
        Queue::assertPushed(ProvisionDemoWorkspace::class);
    }

    public function test_the_honeypot_refuses_bots(): void
    {
        $this->post('/demo', $this->form(['website' => 'http://spam.example']))->assertSessionHasErrors('website');

        $this->assertSame(0, TrialAccount::count());
    }

    public function test_the_captcha_is_checked_with_cloudflare_when_keys_are_set(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get('/demo')->assertInertia(fn ($page) => $page->where('turnstile_site_key', 'site'));
        $this->post('/demo', $this->form())->assertSessionHasErrors('captcha');
        $this->post('/demo', $this->form(['captcha' => 'bad']))->assertSessionHasErrors('captcha');
        $this->post('/demo', $this->form(['captcha' => 'good']))->assertSessionHasNoErrors();

        $this->assertSame(1, TrialAccount::count());
        Http::assertSent(fn ($r) => $r['secret'] === 'secret' && $r['response'] === 'good');
    }

    public function test_without_keys_there_is_no_captcha(): void
    {
        config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

        $this->get('/demo')->assertInertia(fn ($page) => $page->where('turnstile_site_key', null));
        $this->post('/demo', $this->form())->assertSessionHasNoErrors();
    }

    public function test_requests_beyond_the_cap_wait_in_line(): void
    {
        $this->account('active', now()->addDays(3)->toDateTimeString());
        $this->account('provisioning');
        $this->account('active', now()->subDay()->toDateTimeString()); // ended: no longer takes a place

        $this->post('/demo', $this->form())->assertRedirect();

        $waiting = TrialAccount::latest('id')->first();
        $this->assertSame('waiting', $waiting->status);
        $this->assertNotNull($waiting->blockedReason());
        Queue::assertNotPushed(ProvisionDemoWorkspace::class);
        $this->get("/demo/status/{$waiting->code}")->assertInertia(fn ($page) => $page->where('account.status', 'waiting'));
    }

    public function test_prune_removes_long_ended_workspaces_and_moves_the_line(): void
    {
        $old = $this->account('active', now()->subDays(10)->toDateTimeString());
        $recent = $this->account('active', now()->subDays(2)->toDateTimeString());
        $open = $this->account('active', now()->addDays(5)->toDateTimeString());
        $first = $this->account('waiting');
        $second = $this->account('waiting');

        $this->artisan('eolas:prune-workspaces', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('active', $old->fresh()->status);

        $this->artisan('eolas:prune-workspaces')->assertSuccessful();

        $this->assertSame('archived', $old->fresh()->status);
        $this->assertNull($old->fresh()->database);
        $this->assertSame('active', $recent->fresh()->status); // kept a while to be extended
        $this->assertSame('active', $open->fresh()->status);
        // One place free (cap 2, one open): the oldest in line goes first.
        $this->assertSame('provisioning', $first->fresh()->status);
        $this->assertSame('waiting', $second->fresh()->status);
        Queue::assertPushed(ProvisionDemoWorkspace::class, 1);
    }
}
