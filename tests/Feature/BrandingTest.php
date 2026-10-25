<?php

namespace Tests\Feature;

use App\Models\Branding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    private function save(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin())->post('/settings/branding', [
            'app_name' => 'HRIS Nusantara',
            'company_name' => 'PT Contoh Jaya',
            'tagline' => 'Konstruksi',
            'brand_color' => '#1F5FA8',
            'accent_color' => '#f59e0b',
            ...$data,
        ]);
    }

    public function test_the_installation_starts_with_its_own_brand(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding.app_name', 'HR Puspita')
            ->where('branding.company_name', 'PT Riung Mitra Lestari')
            ->where('branding.brand_color', '#586c9a'));
    }

    public function test_the_super_admin_changes_names_colours_and_logo(): void
    {
        $this->save(['logo' => UploadedFile::fake()->image('logo.png', 120, 120)])->assertSessionHasNoErrors();

        $b = Branding::current();
        $this->assertSame('#1f5fa8', $b->brand_color);
        Storage::disk('local')->assertExists($b->logo_path);

        // Every page: the names in the shared props, the colours and the title in the head.
        $html = $this->actingAs($this->admin())->get('/settings/branding')
            ->assertInertia(fn (Assert $page) => $page->where('branding.app_name', 'HRIS Nusantara')->where('branding.tagline', 'Konstruksi'))
            ->getContent();
        $this->assertStringContainsString('--color-brand-600:#1f5fa8', $html);
        $this->assertStringContainsString('--color-accent-400:#f59e0b', $html);
        $this->assertStringContainsString('content="HRIS Nusantara"', $html);

        // The logo, also for a visitor who is not signed in (the login page).
        auth()->logout();
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_without_a_favicon_one_is_drawn_from_the_initial(): void
    {
        $this->save(['delete' => ['favicon', 'logo']])->assertSessionHasNoErrors();
        auth()->logout();

        $svg = $this->get('/branding/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();
        $this->assertStringContainsString('>H</text>', $svg);
        $this->assertStringContainsString('#1f5fa8', $svg);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_a_new_logo_replaces_the_old_file(): void
    {
        $this->save(['logo' => UploadedFile::fake()->image('a.png')]);
        $first = Branding::current()->logo_path;
        Branding::forget();
        $this->save(['logo' => UploadedFile::fake()->image('b.webp')]);

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists(Branding::current()->logo_path);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->save(['brand_color' => 'blue'])->assertSessionHasErrors('brand_color');
        $this->save(['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('logo');
    }

    public function test_only_the_super_admin_may_change_it(): void
    {
        $hr = User::where('role', User::ROLE_HR)->firstOrFail();
        $this->actingAs($hr)->get('/settings/branding')->assertForbidden();
        $this->actingAs($hr)->post('/settings/branding', ['brand_color' => '#000000', 'accent_color' => '#ffffff'])->assertForbidden();
    }
}
