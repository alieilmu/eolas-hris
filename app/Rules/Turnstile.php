<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile: the token the widget put in the form, checked with Cloudflare.
 * Off while no secret key is set (local, tests, an on-premise install). When Cloudflare
 * cannot be reached the form is let through (logged): rate limits and the honeypot still hold.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private ?string $ip = null) {}

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key')) && filled(config('services.turnstile.site_key'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::enabled()) {
            return;
        }
        if (! is_string($value) || $value === '') {
            $fail('Selesaikan verifikasi "Saya bukan robot" terlebih dahulu.');

            return;
        }
        try {
            $ok = Http::asForm()->timeout(5)->post(self::VERIFY, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $value,
                'remoteip' => $this->ip,
            ])->json('success') === true;
        } catch (ConnectionException $e) {
            Log::warning('Turnstile tidak bisa dihubungi: '.$e->getMessage());

            return;
        }
        if (! $ok) {
            $fail('Verifikasi "Saya bukan robot" gagal atau kedaluwarsa. Coba lagi.');
        }
    }
}
