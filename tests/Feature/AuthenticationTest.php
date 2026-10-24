<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/manpower/karyawan')->assertRedirect('/login');
        // "/" is the public landing page for visitors.
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Landing'));
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth.user', null));
    }

    public function test_users_can_log_in_and_return_to_the_intended_page(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_HR]);

        $this->get('/manpower/karyawan')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/manpower/karyawan');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected_with_one_generic_message(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Email / NRP atau password salah.']);

        $this->post('/login', ['email' => 'tidak-ada@eolas.local', 'password' => 'salah'])
            ->assertSessionHasErrors(['email' => 'Email / NRP atau password salah.']);

        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
    }

    public function test_signed_in_users_skip_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_users_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_shared_props_expose_the_user_role_and_menus(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['recruitment' => 'view']]);

        $this->actingAs($admin)
            ->get('/recruitment/tracking')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Recruitment/Tracking')
                ->where('auth.user.role', 'HR')
                ->where('auth.user.role_label', 'Admin')
                ->where('auth.menus', ['recruitment' => 'view'])
                ->where('auth.can.viewSensitiveData', false)
                ->where('auth.can.manageUsers', false)
                ->missing('auth.user.password'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['recruitment' => 'view', 'karyawan' => 'edit']]))
            ->get('/recruitment/tracking')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.viewSensitiveData', true)
                ->where('auth.can.manageUsers', false));
    }

    public function test_gates_follow_the_account_type_and_its_menus(): void
    {
        $cases = [
            'super admin' => [User::factory()->create(['role' => User::ROLE_ADMIN]), true, true],
            'admin with Man Power' => [User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['karyawan' => 'view']]), true, false],
            'admin without it' => [User::factory()->create(['role' => User::ROLE_HR, 'permissions' => ['roster' => 'edit']]), false, false],
            'karyawan' => [User::factory()->create(['role' => User::ROLE_KARYAWAN]), false, false],
        ];

        foreach ($cases as $name => [$user, $sensitive, $manage]) {
            $this->assertSame($sensitive, Gate::forUser($user)->allows('view-sensitive-data'), $name);
            $this->assertSame($manage, Gate::forUser($user)->allows('manage-users'), $name);
        }
        $this->assertTrue(Gate::forUser($cases['admin without it'][0])->allows('edit-menu', 'roster'));
        $this->assertFalse(Gate::forUser($cases['admin with Man Power'][0])->allows('edit-menu', 'karyawan'));
    }
}
