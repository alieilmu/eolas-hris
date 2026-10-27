<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Security\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verifikasi 2 langkah: an account turns it on with an authenticator app (scan, confirm a code,
 * keep the recovery codes) and gives a code after its password from then on. The Super Admin
 * can reset it for an account that lost its phone.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $pending = $request->session()->get(TwoFactor::SESSION_PENDING);
        $setup = null;
        if (! $user->hasTwoFactor() && $pending) {
            $uri = TwoFactor::uri($user, $pending, Branding::current()->appName());
            $setup = ['qr' => TwoFactor::qrSvg($uri), 'secret' => trim(chunk_split($pending, 4, ' '))];
        }

        return Inertia::render('Account/TwoFactor', [
            'enabled' => $user->hasTwoFactor(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toDateTimeString(),
            'required' => TwoFactor::required($user),
            'skippable' => TwoFactor::skippable() && TwoFactor::required($user) && ! $user->hasTwoFactor(),
            'setup' => $setup,
            'recovery_codes' => $request->session()->pull('two_factor.new_codes'),
            'recovery_left' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : null,
        ]);
    }

    /** Starts the setup: a new secret, kept in the session until a code confirms it. */
    public function start(Request $request): RedirectResponse
    {
        abort_if($request->user()->hasTwoFactor(), 422);
        $request->session()->put(TwoFactor::SESSION_PENDING, TwoFactor::newSecret());

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = $request->session()->get(TwoFactor::SESSION_PENDING);
        abort_if($user->hasTwoFactor() || ! $secret, 422);
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        if (! TwoFactor::verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => 'Kode salah atau sudah kedaluwarsa. Masukkan kode yang sedang tampil di aplikasi.']);
        }
        $codes = TwoFactor::recoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(), 'two_factor_last_step' => (int) floor(time() / 30),
        ])->save();
        $request->session()->forget([TwoFactor::SESSION_PENDING, TwoFactor::SESSION_SKIPPED]);
        $request->session()->put(TwoFactor::SESSION_PASSED, true);
        $request->session()->flash('two_factor.new_codes', $codes);
        Audit::log('auth.two_factor_enabled', $user);
        Inertia::flash('success', 'Verifikasi 2 langkah aktif. Simpan kode cadangan di tempat aman.');

        return redirect()->route('account.two-factor');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Password salah.']);
        abort_unless($request->user()->hasTwoFactor(), 422);
        $codes = TwoFactor::recoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $request->session()->flash('two_factor.new_codes', $codes);
        Inertia::flash('success', 'Kode cadangan baru dibuat; kode lama tidak berlaku lagi.');

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Password salah.']);
        if (TwoFactor::required($user)) {
            throw ValidationException::withMessages(['password' => 'Akun dengan menu payroll wajib memakai 2FA; tidak bisa dimatikan.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        Audit::log('auth.two_factor_disabled', $user);
        Inertia::flash('success', 'Verifikasi 2 langkah dimatikan.');

        return back();
    }

    /** Demo workspace only: payroll pages open without 2FA for this session. */
    public function skip(Request $request): RedirectResponse
    {
        abort_unless(TwoFactor::skippable(), 404);
        $request->session()->put(TwoFactor::SESSION_SKIPPED, true);

        return redirect()->intended('/compensation/payroll');
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasTwoFactor() || $request->session()->get(TwoFactor::SESSION_PASSED) === true) {
            return redirect('/');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['nullable', 'string', 'max:10'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $ok = filled($data['recovery_code'] ?? null)
            ? TwoFactor::useRecoveryCode($user, $data['recovery_code'])
            : filled($data['code'] ?? null) && TwoFactor::verify($user->two_factor_secret, $data['code'], $user);
        if (! $ok) {
            Audit::log('auth.two_factor_failed', $user);
            throw ValidationException::withMessages(['code' => 'Kode salah. Coba lagi dengan kode yang sedang tampil.']);
        }
        if (filled($data['recovery_code'] ?? null)) {
            Audit::log('auth.recovery_code_used', $user, null, ['left' => count($user->fresh()->two_factor_recovery_codes ?? [])]);
        }
        $request->session()->regenerate();
        $request->session()->put(TwoFactor::SESSION_PASSED, true);

        return redirect()->intended('/');
    }

    /** Super Admin: an account lost its phone — 2FA off, to be set up again. */
    public function reset(User $user): RedirectResponse
    {
        abort_if($user->is(Auth::user()), 422);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        Audit::log('auth.two_factor_reset', $user, "2FA {$user->name} direset");
        Inertia::flash('success', "2FA {$user->name} direset. Akun ini mengatur ulang 2FA saat membuka payroll.");

        return back();
    }
}
