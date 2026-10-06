<?php

namespace App\Http\Requests\Auth;

use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP before a lockout. */
    private const MAX_ATTEMPTS = 5;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // An email (admins) or an NRP (employees).
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
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
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim($this->string('email'));
        // Admins sign in with their email, employees with their NRP.
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('role', User::ROLE_KARYAWAN)->whereHas('karyawan', fn ($q) => $q->where('nrp', $login))->first();

        $valid = $user && Hash::check($this->string('password'), $user->password);
        if (! $valid) {
            RateLimiter::hit($this->throttleKey());

            // One message for both cases so the form doesn't reveal which accounts exist.
            throw ValidationException::withMessages([
                'email' => 'Email / NRP atau password salah.',
            ]);
        }
        if (! $user->is_active || ($user->isKaryawan() && $user->karyawan?->status === Karyawan::STATUS_KELUAR)) {
            throw ValidationException::withMessages(['email' => 'Akun ini tidak aktif. Hubungi HR.']);
        }

        RateLimiter::clear($this->throttleKey());
        Auth::login($user, $this->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->save();
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
