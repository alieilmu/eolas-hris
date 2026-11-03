<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\UserPasskey;
use App\Support\Audit\Audit;
use App\Support\Security\AppLock;
use App\Support\Security\Passkeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The installed app on a phone: the lock screen (PIN, or the phone's fingerprint / face), and
 * Akun → Keamanan Aplikasi where the account sets its PIN and registers this phone.
 */
class AppLockController extends Controller
{
    /** The lock screen. */
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->get(AppLock::SESSION_LOCKED)) {
            return redirect('/');
        }
        $user = $request->user();

        return Inertia::render('Auth/AppUnlock', [
            'name' => $user->name,
            'pin' => AppLock::hasPin($user),
            'passkey' => AppLock::hasPasskey($user),
            'tries_left' => AppLock::MAX_FAILURES - AppLock::failures($request),
        ]);
    }

    /** The installed app was opened again: lock it until the PIN is given. */
    public function lock(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($request->session()->get(AppLock::SESSION_DEVICE) && AppLock::canUnlock($user)) {
            AppLock::lock($request);

            return redirect()->route('app-lock');
        }

        return back();
    }

    public function pin(Request $request): HttpResponse
    {
        $pin = $request->validate(['pin' => ['required', 'string', 'max:12']])['pin'];
        $user = $request->user();
        if (AppLock::checkPin($request, $user, $pin)) {
            AppLock::unlocked($request);

            return redirect()->intended('/');
        }
        Audit::log('auth.app_pin_failed', $user);
        if (AppLock::failures($request) >= AppLock::MAX_FAILURES) {
            return $this->signOut($request, 'PIN salah '.AppLock::MAX_FAILURES.' kali. Masuk lagi dengan password.');
        }

        throw ValidationException::withMessages(['pin' => 'PIN salah. Sisa '.(AppLock::MAX_FAILURES - AppLock::failures($request)).' kali percobaan.']);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        return response()->json(Passkeys::requestOptions($request, $request->user()));
    }

    public function passkey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:255'], 'clientDataJSON' => ['required', 'string'],
            'authenticatorData' => ['required', 'string'], 'signature' => ['required', 'string'],
        ]);
        Passkeys::verify($request, $request->user(), $data);
        AppLock::unlocked($request);

        return redirect()->intended('/');
    }

    /** Akun → Keamanan Aplikasi. */
    public function settings(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/AppSecurity', [
            'pin' => AppLock::hasPin($user),
            'passkeys' => UserPasskey::where('user_id', $user->id)->latest()->get()
                ->map(fn (UserPasskey $p) => ['id' => $p->id, 'name' => $p->name ?: 'Perangkat', 'created_at' => $p->created_at?->toDateTimeString(), 'last_used_at' => $p->last_used_at?->toDateTimeString()]),
            'days' => AppLock::days(),
            'device' => (bool) $request->session()->get(AppLock::SESSION_DEVICE),
        ]);
    }

    public function savePin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'pin' => AppLock::pinRules(),
        ], ['password.current_password' => 'Password salah.', 'pin.digits' => 'PIN harus 6 angka.', 'pin.confirmed' => 'Ulangi PIN tidak sama.']);
        $user = $request->user();
        $new = ! AppLock::hasPin($user);
        AppLock::setPin($user, $data['pin']);
        Audit::log('auth.app_pin_set', $user);
        Inertia::flash('success', $new ? 'PIN dibuat. Aplikasi di HP ini dibuka dengan PIN.' : 'PIN diganti.');

        return back();
    }

    public function destroyPin(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Password salah.']);
        $user = $request->user();
        $user->forceFill(['app_pin' => null])->save();
        Audit::log('auth.app_pin_removed', $user);
        Inertia::flash('success', 'PIN dihapus.');

        return back();
    }

    public function passkeyCreationOptions(Request $request): JsonResponse
    {
        return response()->json(Passkeys::creationOptions($request, $request->user()));
    }

    public function storePasskey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:255'], 'clientDataJSON' => ['required', 'string'],
            'publicKey' => ['required', 'string', 'max:2048'], 'algorithm' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $key = Passkeys::register($request, $request->user(), $data);
        Audit::log('auth.passkey_added', $request->user(), null, ['name' => $key->name]);
        Inertia::flash('success', 'Sidik jari / wajah di HP ini bisa membuka aplikasi.');

        return back();
    }

    public function destroyPasskey(Request $request, UserPasskey $passkey): RedirectResponse
    {
        abort_unless($passkey->user_id === $request->user()->id, 404);
        $passkey->delete();
        Audit::log('auth.passkey_removed', $request->user(), null, ['name' => $passkey->name]);
        Inertia::flash('success', 'Perangkat dihapus.');

        return back();
    }

    private function signOut(Request $request, string $message): HttpResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(AppLock::COOKIE));

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
