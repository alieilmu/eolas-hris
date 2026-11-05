<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use App\Support\Security\Passkeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push without a package: the server's own VAPID key (RFC 8292, one per installation,
 * made on first use), the message encrypted for the phone (RFC 8291, aes128gcm), and the
 * POST to the phone's push service (Google, Apple, Mozilla, Microsoft). Optional: it needs
 * the server to reach the internet; without it the bell inside the app still has everything.
 */
class WebPush
{
    /** DER header of a P-256 public key (SubjectPublicKeyInfo) before its 65 raw bytes. */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Failed sends in a row after which a phone is dropped (it subscribes again when opened). */
    public const GIVE_UP = 30;

    public static function enabled(): bool
    {
        return (bool) config('eolas.push.enabled') && extension_loaded('openssl');
    }

    /** The VAPID public key the browser subscribes with (base64url, 65 bytes uncompressed). */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /** @return array{public: string, private: string} */
    private static function keys(): array
    {
        static $keys = [];
        $path = (string) config('eolas.push.key_path');
        if (isset($keys[$path])) {
            return $keys[$path];
        }
        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));
            $fh = fopen($path.'.lock', 'c');
            flock($fh, LOCK_EX);
            try {
                if (! is_file($path)) {
                    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                    openssl_pkey_export($key, $pem);
                    File::put($path, json_encode(['public' => Passkeys::b64url(self::rawPublic($key)), 'private' => $pem, 'created_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
                    @chmod($path, 0600);
                }
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['public']) || empty($data['private'])) {
            throw new RuntimeException("Kunci VAPID rusak: {$path}");
        }

        return $keys[$path] = ['public' => $data['public'], 'private' => $data['private']];
    }

    /** The push service may be one of the known ones only (the server POSTs to it). */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ((array) config('eolas.push.hosts') as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends $payload to one phone. The status of the push service: 201 delivered to it,
     * 404/410 the phone unsubscribed (the row is removed), 0 no connection.
     */
    public static function send(PushSubscription $s, array $payload, int $ttl = 86400, string $urgency = 'normal'): int
    {
        $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $s->public_key, $s->auth_token);
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => self::authorization($s->endpoint),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) $ttl,
                'Urgency' => $urgency,
            ])->withBody($body, 'application/octet-stream')->post($s->endpoint);
            $status = $response->status();
        } catch (ConnectionException) {
            $status = 0;
        }

        if (in_array($status, [404, 410], true)) {
            $s->delete();
        } elseif ($status >= 200 && $status < 300) {
            $s->forceFill(['last_used_at' => now(), 'failures' => 0])->save();
        } elseif ($s->failures + 1 >= self::GIVE_UP) {
            $s->delete();
        } else {
            $s->forceFill(['failures' => $s->failures + 1])->save();
        }

        return $status;
    }

    /** "vapid t=<JWT>, k=<public key>" for the endpoint's push service. */
    public static function authorization(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $audience = "{$p['scheme']}://{$p['host']}".(isset($p['port']) ? ":{$p['port']}" : '');
        $header = Passkeys::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = Passkeys::b64url(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => self::subject()], JSON_UNESCAPED_SLASHES));
        $keys = self::keys();
        openssl_sign("{$header}.{$claims}", $der, $keys['private'], OPENSSL_ALGO_SHA256);

        return "vapid t={$header}.{$claims}.".Passkeys::b64url(self::derToRaw($der)).", k={$keys['public']}";
    }

    /** Who to contact about this server's pushes: a mailto: or the app's https address. */
    private static function subject(): string
    {
        $subject = (string) config('eolas.push.subject');
        if ($subject === '') {
            $url = (string) config('app.url');
            $subject = str_starts_with($url, 'https://') ? $url : 'mailto:admin@localhost';
        }

        return $subject;
    }

    /**
     * RFC 8291: an ECDH key just for this message with the phone's key, mixed with its auth
     * secret, gives the AES-128-GCM key; one record, the 0x02 delimiter, no padding.
     */
    public static function encrypt(string $plain, string $phoneKey, string $authToken): string
    {
        $uaPublic = Passkeys::unb64url($phoneKey);
        $auth = Passkeys::unb64url($authToken);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) {
            throw new RuntimeException('Kunci langganan push tidak valid.');
        }
        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive(self::publicPem($uaPublic), $local, 32);
        if ($shared === false) {
            throw new RuntimeException('Kunci langganan push tidak valid.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00".$uaPublic.$asPublic, $auth);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $cipher = openssl_encrypt($plain."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    public static function publicPem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** 0x04 || x || y of an EC key. */
    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /** An ECDSA signature from OpenSSL (DER) as JWT wants it: r || s, 32 bytes each. */
    private static function derToRaw(string $der): string
    {
        // 0x30 len, then 0x02 len r, 0x02 len s; at $at the length byte of each integer.
        $at = 3 + (ord($der[1]) & 0x80 ? 1 : 0);
        $raw = '';
        foreach ([1, 2] as $_) {
            $len = ord($der[$at]);
            $raw .= str_pad(ltrim(substr($der, $at + 1, $len), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $at += $len + 2;
        }

        return $raw;
    }
}
