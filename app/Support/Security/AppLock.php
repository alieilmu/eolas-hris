<?php

namespace App\Support\Security;

use App\Models\Branding;
use App\Models\User;
use App\Models\UserPasskey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;

/**
 * The installed app (PWA) on a phone: a sign-in there lasts longer (Settings → White Label,
 * "Sesi di aplikasi HP"), and once the app is opened again — or the short session ran out and
 * the long one brought the account back — it asks for the account's PIN or the phone's
 * fingerprint / face (a WebAuthn passkey) before showing anything.
 */
class AppLock
{
    /** On the phone: this browser signed in through the installed app. */
    public const COOKIE = 'eolas_app';

    /** The session was started in the installed app. */
    public const SESSION_DEVICE = 'app.device';

    /** Locked until the PIN or a passkey is given. */
    public const SESSION_LOCKED = 'app.locked';

    public const SESSION_FAILURES = 'app.failures';

    public const SESSION_CHALLENGE = 'app.challenge';

    /** Wrong PINs before the account is signed out. */
    public const MAX_FAILURES = 5;

    /** Days a sign-in in the installed app lasts; 0 = as in the browser. */
    public static function days(): int
    {
        return (int) (Branding::query()->value('mobile_session_days') ?? 0);
    }

    /** At sign-in from the installed app: a long remember-me, and the cookie that marks the phone. */
    public static function startOnDevice(Request $request): bool
    {
        $request->session()->put(self::SESSION_DEVICE, true);
        $days = self::days();
        if ($days < 1) {
            return false;
        }
        Cookie::queue(Cookie::make(self::COOKIE, '1', $days * 1440));

        return true;
    }

    public static function hasPin(User $user): bool
    {
        return (bool) $user->app_pin;
    }

    public static function hasPasskey(User $user): bool
    {
        return UserPasskey::where('user_id', $user->id)->exists();
    }

    /** Whether the account can open a locked app at all. */
    public static function canUnlock(User $user): bool
    {
        return self::hasPin($user) || self::hasPasskey($user);
    }

    /** For the page props: null outside the installed app. */
    public static function share(Request $request, ?User $user): ?array
    {
        if (! $user || ! $request->session()->get(self::SESSION_DEVICE)) {
            return null;
        }

        return ['pin' => self::hasPin($user), 'passkey' => self::hasPasskey($user), 'days' => self::days()];
    }

    public static function lock(Request $request): void
    {
        $request->session()->put(self::SESSION_LOCKED, true);
    }

    public static function unlocked(Request $request): void
    {
        $request->session()->forget([self::SESSION_LOCKED, self::SESSION_FAILURES, self::SESSION_CHALLENGE]);
    }

    /** Six digits; not one digit repeated nor a straight run (111111, 123456, 654321). */
    public static function pinRules(): array
    {
        return ['required', 'digits:6', 'confirmed', function (string $attribute, mixed $value, \Closure $fail) {
            $v = (string) $value;
            if (count(array_unique(str_split($v))) === 1 || str_contains('0123456789', $v) || str_contains('9876543210', $v)) {
                $fail('PIN terlalu mudah ditebak. Hindari angka sama atau berurutan.');
            }
        }];
    }

    public static function setPin(User $user, string $pin): void
    {
        $user->forceFill(['app_pin' => Hash::make($pin)])->save();
    }

    /** A wrong PIN is counted in the session; the caller signs out after MAX_FAILURES. */
    public static function checkPin(Request $request, User $user, string $pin): bool
    {
        if ($user->app_pin && Hash::check($pin, $user->app_pin)) {
            return true;
        }
        $request->session()->put(self::SESSION_FAILURES, (int) $request->session()->get(self::SESSION_FAILURES, 0) + 1);

        return false;
    }

    public static function failures(Request $request): int
    {
        return (int) $request->session()->get(self::SESSION_FAILURES, 0);
    }

    /** A fresh WebAuthn challenge, kept in the session for the answer. */
    public static function challenge(Request $request): string
    {
        $challenge = Passkeys::b64url(random_bytes(32));
        $request->session()->put(self::SESSION_CHALLENGE, $challenge);

        return $challenge;
    }
}
