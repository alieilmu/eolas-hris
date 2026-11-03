<?php

namespace App\Support\Security;

use App\Models\Branding;
use App\Models\User;
use App\Models\UserPasskey;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WebAuthn for opening the installed app with the phone's fingerprint / face. Only what that
 * needs: a platform key made on the phone (its public key read by the browser,
 * getPublicKey(), so no attestation is parsed) and assertions signed with it. ES256 and
 * RS256, the two every phone offers.
 */
class Passkeys
{
    /** COSE algorithm => name. */
    public const ALGORITHMS = [-7 => 'ES256', -257 => 'RS256'];

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64url(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? '' : $bytes;
    }

    /** What navigator.credentials.create() needs. */
    public static function creationOptions(Request $request, User $user): array
    {
        return [
            'challenge' => AppLock::challenge($request),
            'rp' => ['id' => $request->getHost(), 'name' => (string) Branding::current()->appName()],
            'user' => ['id' => self::b64url(hash('sha256', $request->getHost().'|'.$user->id, true)), 'name' => (string) ($user->email ?? $user->employee?->employee_number ?? $user->id), 'displayName' => $user->name],
            'pubKeyCredParams' => array_map(fn (int $alg) => ['type' => 'public-key', 'alg' => $alg], array_keys(self::ALGORITHMS)),
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'discouraged'],
            'excludeCredentials' => self::credentials($user),
            'attestation' => 'none',
            'timeout' => 60000,
        ];
    }

    /** What navigator.credentials.get() needs. */
    public static function requestOptions(Request $request, User $user): array
    {
        return [
            'challenge' => AppLock::challenge($request),
            'rpId' => $request->getHost(),
            'allowCredentials' => self::credentials($user),
            'userVerification' => 'required',
            'timeout' => 60000,
        ];
    }

    private static function credentials(User $user): array
    {
        return UserPasskey::where('user_id', $user->id)->pluck('credential_id')
            ->map(fn (string $id) => ['type' => 'public-key', 'id' => $id, 'transports' => ['internal']])->all();
    }

    /**
     * A new key from navigator.credentials.create().
     *
     * @param  array{id: string, clientDataJSON: string, publicKey: string, algorithm: int, name?: ?string}  $data
     *
     * @throws ValidationException
     */
    public static function register(Request $request, User $user, array $data): UserPasskey
    {
        self::clientData($request, $data['clientDataJSON'], 'webauthn.create');
        $der = self::unb64url($data['publicKey']);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (! isset(self::ALGORITHMS[$data['algorithm']]) || ! openssl_pkey_get_public($pem)) {
            throw ValidationException::withMessages(['passkey' => 'Perangkat ini tidak didukung untuk sidik jari / wajah.']);
        }
        if ($data['id'] === '' || UserPasskey::where('credential_id', $data['id'])->exists()) {
            throw ValidationException::withMessages(['passkey' => 'Sidik jari / wajah ini sudah terdaftar.']);
        }

        return UserPasskey::create([
            'user_id' => $user->id, 'credential_id' => $data['id'], 'public_key' => $pem, 'algorithm' => $data['algorithm'],
            'name' => $data['name'] ?? null,
        ]);
    }

    /**
     * An answer from navigator.credentials.get(): signed by one of the account's keys, for
     * this site, with the person verified on the phone (fingerprint, face or the phone's PIN).
     *
     * @param  array{id: string, clientDataJSON: string, authenticatorData: string, signature: string}  $data
     *
     * @throws ValidationException
     */
    public static function verify(Request $request, User $user, array $data): UserPasskey
    {
        $fail = fn () => throw ValidationException::withMessages(['passkey' => 'Sidik jari / wajah tidak dikenali. Coba lagi atau pakai PIN.']);
        $key = UserPasskey::where('user_id', $user->id)->where('credential_id', $data['id'])->first() ?? $fail();
        $client = self::clientData($request, $data['clientDataJSON'], 'webauthn.get');

        $auth = self::unb64url($data['authenticatorData']);
        if (strlen($auth) < 37 || ! hash_equals(hash('sha256', $request->getHost(), true), substr($auth, 0, 32))) {
            $fail();
        }
        $flags = ord($auth[32]);
        // User present and user verified.
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            $fail();
        }
        $count = unpack('N', substr($auth, 33, 4))[1];
        // A count that does not go up means a copied key (phones that keep no count send 0).
        if ($count > 0 && $count <= $key->sign_count) {
            $fail();
        }
        if (openssl_verify($auth.hash('sha256', $client, true), self::unb64url($data['signature']), $key->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            $fail();
        }
        $key->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();

        return $key;
    }

    /** The browser's clientDataJSON, checked against the session's challenge and this site. Returns the raw JSON. */
    private static function clientData(Request $request, string $encoded, string $type): string
    {
        $raw = self::unb64url($encoded);
        $data = json_decode($raw, true);
        $challenge = $request->session()->pull(AppLock::SESSION_CHALLENGE);
        if (! is_array($data) || ($data['type'] ?? null) !== $type || ! $challenge
            || ! hash_equals($challenge, (string) ($data['challenge'] ?? ''))
            || ($data['origin'] ?? null) !== $request->getSchemeAndHttpHost()) {
            throw ValidationException::withMessages(['passkey' => 'Permintaan sidik jari / wajah kedaluwarsa. Coba lagi.']);
        }

        return $raw;
    }
}
