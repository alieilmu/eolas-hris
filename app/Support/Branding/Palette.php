<?php

namespace App\Support\Branding;

/**
 * The Tailwind colour scales (brand-50 … brand-950, accent-50 … accent-800) drawn from the
 * two colours of the white label, as CSS variables that override the ones in app.css.
 * The chosen colour is one step of its scale (brand-600, accent-400); the lighter steps are
 * mixed with white and the darker ones with black, close to how the built-in scales run.
 */
class Palette
{
    /** step => share of white (> 0) or black (< 0) mixed in. */
    private const BRAND = [50 => .93, 100 => .85, 200 => .7, 300 => .5, 400 => .28, 500 => .13, 600 => 0, 700 => -.18, 800 => -.34, 900 => -.5, 950 => -.66];

    private const ACCENT = [50 => .9, 100 => .75, 200 => .5, 300 => .3, 400 => 0, 500 => -.09, 600 => -.23, 700 => -.43, 800 => -.57];

    public static function css(string $brand, string $accent): string
    {
        $vars = [];
        foreach (self::BRAND as $step => $mix) {
            $vars[] = "--color-brand-{$step}:".self::mix($brand, $mix);
        }
        foreach (self::ACCENT as $step => $mix) {
            $vars[] = "--color-accent-{$step}:".self::mix($accent, $mix);
        }

        return ':root{'.implode(';', $vars).'}';
    }

    /** "#3f5f8f" for a valid #rrggbb, else null. */
    public static function normalize(?string $hex): ?string
    {
        $hex = strtolower(trim((string) $hex));

        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : null;
    }

    private static function mix(string $hex, float $mix): string
    {
        $target = $mix >= 0 ? 255 : 0;
        $share = abs($mix);

        return '#'.implode('', array_map(fn (int $c) => sprintf('%02x', (int) round($c + ($target - $c) * $share)), self::rgb($hex)));
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalize($hex) ?? '#000000', '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
