<?php

namespace App\Support\Security;

use App\Models\User;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor sign-in with an authenticator app (TOTP: Google Authenticator, Microsoft
 * Authenticator, Authy …). Required for accounts with the payroll menus; in a demo
 * workspace it may be skipped, so a trial is not held up by it.
 */
class TwoFactor
{
    /** Menus whose accounts must sign in with a second factor. */
    public const REQUIRED_FOR = ['payroll-run', 'payroll-approval', 'payroll-inputs', 'loans'];

    public const SESSION_PASSED = 'two_factor.passed';

    public const SESSION_SKIPPED = 'two_factor.skipped';

    public const SESSION_PENDING = 'two_factor.pending_secret';

    private static function engine(): Google2FA
    {
        return new Google2FA;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    public static function required(User $user): bool
    {
        return ! $user->isEmployee() && $user->canView(...self::REQUIRED_FOR);
    }

    /** In a demo workspace the requirement can be skipped for the session. */
    public static function skippable(): bool
    {
        return Tenant::current() !== null;
    }

    /** The otpauth:// address an authenticator app reads from the QR code. */
    public static function uri(User $user, string $secret, string $issuer): string
    {
        return self::engine()->getQRCodeUrl($issuer, $user->email ?? $user->name, $secret);
    }

    public static function qrSvg(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /** A code for $secret now (± 30 s); with $user, a code is not accepted twice. */
    public static function verify(string $secret, string $code, ?User $user = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $step = self::engine()->verifyKeyNewer($secret, $code, $user?->two_factor_last_step, 1);
        if ($step === false) {
            return false;
        }
        if ($user) {
            $user->forceFill(['two_factor_last_step' => $step === true ? (int) floor(time() / 30) : $step])->save();
        }

        return true;
    }

    /** @return list<string> */
    public static function recoveryCodes(): array
    {
        return array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }

    /** Uses up a recovery code; false when it is not one of the account's. */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $code = Str::lower(trim($code));
        if (! in_array($code, $codes, true)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$code]))])->save();

        return true;
    }
}
