<?php

namespace App\Http\Requests\Auth;

use App\Models\TrialAccount;
use App\Models\Employee;
use App\Models\User;
use App\Rules\Turnstile;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP before a lockout. */
    private const MAX_ATTEMPTS = 5;

    /** The demo workspace signed in to, or null for the main application. */
    public ?string $tenant = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // An email (admins) or an NRP (employees).
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            // Kode workspace demo: needed for an NRP or a seeded account inside a workspace.
            'workspace' => ['nullable', 'string', 'max:16'],
            // Cloudflare Turnstile, when its keys are set (see App\Rules\Turnstile).
            'captcha' => [Rule::requiredIf(Turnstile::enabled()), new Turnstile($this->ip())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email atau NRP wajib diisi.',
            'password.required' => 'Password wajib diisi.',
            'captcha.required' => 'Selesaikan verifikasi "Saya bukan robot" terlebih dahulu.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim($this->string('email'));
        $this->openWorkspace($login);
        // Admins sign in with their email, employees with their NRP.
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('role', User::ROLE_EMPLOYEE)->whereHas('employee', fn ($q) => $q->where('employee_number', $login))->first();

        $valid = $user && Hash::check($this->string('password'), $user->password);
        if (! $valid) {
            RateLimiter::hit($this->throttleKey());
            Audit::log('auth.login_failed', $user, null, ['login' => Str::limit($login, 100)], $user);

            // One message for both cases so the form doesn't reveal which accounts exist.
            throw ValidationException::withMessages([
                'email' => 'Email / NRP atau password salah.',
            ]);
        }
        if (! $user->is_active || ($user->isEmployee() && $user->employee?->status === Employee::STATUS_TERMINATED)) {
            throw ValidationException::withMessages(['email' => 'Akun ini tidak aktif. Hubungi HR.']);
        }

        RateLimiter::clear($this->throttleKey());
        Auth::login($user, $this->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->save();
    }

    /**
     * The workspace to look the account up in: the one whose code was given, or the demo
     * account owning this email; otherwise the main application.
     *
     * @throws ValidationException
     */
    private function openWorkspace(string $login): void
    {
        $code = Str::lower(trim((string) $this->string('workspace')));
        $account = match (true) {
            $code !== '' => TrialAccount::where('code', $code)->first()
                ?? throw ValidationException::withMessages(['email' => 'Kode workspace tidak ditemukan.']),
            str_contains($login, '@') => TrialAccount::where('email', $login)->first(),
            default => null,
        };
        if (! $account) {
            Tenant::central();

            return;
        }
        if ($reason = $account->blockedReason()) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages(['email' => $reason]);
        }
        Tenant::use($account->database);
        $this->tenant = $account->database;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
