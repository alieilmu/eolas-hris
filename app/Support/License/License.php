<?php

namespace App\Support\License;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * The license of a client installation: a JSON file {"payload": base64 JSON, "signature":
 * base64} signed by Eolas with Ed25519. The payload names the company, the tier, the most
 * active employees allowed and until when support (updates) runs. Without a valid license the
 * client edition opens only the license page; past the support date it keeps working but takes
 * no updates.
 */
class License
{
    /** Eolas' public key (the private one stays with the Production team). */
    private const PUBLIC_KEY = 'IBxiCa8nFLrPnp7a209spq81nV9lTr4iZVv5N/eqpTc=';

    public const TIERS = ['starter' => 'Starter (s.d. 100 karyawan)', 'growth' => 'Growth (101–300)', 'enterprise' => 'Enterprise (301–1.000)', 'custom' => 'Custom'];

    private static ?string $testKey = null;

    private static ?array $loaded = null;

    /** Tests sign their own licenses. */
    public static function useKeyForTesting(?string $publicKey): void
    {
        self::$testKey = $publicKey;
        self::$loaded = null;
    }

    public static function forget(): void
    {
        self::$loaded = null;
    }

    public static function path(): string
    {
        return config('eolas.license_path') ?: storage_path('app/license.json');
    }

    /** Signs a payload: what eolas:license:issue writes. */
    public static function sign(array $payload, string $secretKey): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return json_encode([
            'payload' => base64_encode($json),
            'signature' => base64_encode(sodium_crypto_sign_detached($json, base64_decode($secretKey, true) ?: $secretKey)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * The license as checked: ['valid' => bool, 'reason' => ?string, ...payload].
     *
     * @return array{valid: bool, reason: ?string, license_id?: string, company?: string, tier?: string, max_employees?: int, support_until?: string, issued_at?: string}
     */
    public static function check(?string $contents = null): array
    {
        if ($contents === null && self::$loaded !== null) {
            return self::$loaded;
        }
        $raw = $contents ?? (File::exists(self::path()) ? File::get(self::path()) : null);
        $result = self::verify($raw);
        if ($contents === null) {
            self::$loaded = $result;
        }

        return $result;
    }

    private static function verify(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['valid' => false, 'reason' => 'Lisensi belum dipasang.'];
        }
        $file = json_decode($raw, true);
        $json = is_array($file) ? base64_decode((string) ($file['payload'] ?? ''), true) : false;
        $signature = is_array($file) ? base64_decode((string) ($file['signature'] ?? ''), true) : false;
        if ($json === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['valid' => false, 'reason' => 'File lisensi rusak atau bukan file lisensi Eolas.'];
        }
        $key = base64_decode(self::$testKey ?? self::PUBLIC_KEY, true);
        if (! sodium_crypto_sign_verify_detached($signature, $json, $key)) {
            return ['valid' => false, 'reason' => 'Tanda tangan lisensi tidak cocok: file diubah atau bukan dari Eolas.'];
        }
        $p = json_decode($json, true);
        if (! is_array($p) || ! isset($p['company'], $p['max_employees'], $p['support_until'])) {
            return ['valid' => false, 'reason' => 'Isi lisensi tidak lengkap.'];
        }

        return ['valid' => true, 'reason' => null] + $p;
    }

    public static function valid(): bool
    {
        return self::check()['valid'];
    }

    public static function maxEmployees(): ?int
    {
        $l = self::check();

        return $l['valid'] ? (int) $l['max_employees'] : null;
    }

    /** Support (updates) still running today. */
    public static function supported(): bool
    {
        $l = self::check();

        return $l['valid'] && CarbonImmutable::parse($l['support_until'])->endOfDay()->isFuture();
    }

    public static function activeEmployees(): int
    {
        return Employee::withoutGlobalScopes()->where('status', '!=', Employee::STATUS_TERMINATED)->count();
    }

    /** For the license page and the banner. */
    public static function summary(): array
    {
        $l = self::check();
        $used = self::activeEmployees();

        return [
            'valid' => $l['valid'], 'reason' => $l['reason'],
            'license_id' => $l['license_id'] ?? null, 'company' => $l['company'] ?? null,
            'tier' => $l['tier'] ?? null, 'tier_label' => isset($l['tier']) ? (self::TIERS[$l['tier']] ?? $l['tier']) : null,
            'max_employees' => isset($l['max_employees']) ? (int) $l['max_employees'] : null, 'active_employees' => $used,
            'support_until' => $l['support_until'] ?? null, 'issued_at' => $l['issued_at'] ?? null,
            'supported' => self::supported(),
        ];
    }
}
