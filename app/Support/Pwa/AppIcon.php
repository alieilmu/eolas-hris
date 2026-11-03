<?php

namespace App\Support\Pwa;

use App\Models\Branding;
use Illuminate\Support\Facades\Storage;

/**
 * The installed app's icon on the phone's home screen, drawn from White Label: the uploaded
 * logo (square mark) on white, or the app's initial in the brand colours like the favicon.
 * "maskable" fills the whole square and keeps the mark inside the middle 60%, since Android
 * crops it to a circle or rounded square.
 */
class AppIcon
{
    public const SIZES = [180, 192, 512];

    /** Drawn larger, then scaled down: GD draws no smooth curves of its own. */
    private const SCALE = 4;

    public static function png(Branding $b, int $size, bool $maskable): string
    {
        $big = $size * self::SCALE;
        $img = imagecreatetruecolor($big, $big);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        $logo = self::logo($b);
        if ($logo) {
            self::square($img, $big, $maskable ? 0 : (int) ($big * 0.18), self::color($img, '#ffffff'));
            self::place($img, $logo, $big, $maskable ? 0.6 : 0.78);
        } else {
            $brand = self::color($img, $b->brandColor());
            if ($maskable) {
                imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, $brand);
            } else {
                self::square($img, $big, (int) ($big * 0.22), self::color($img, '#ffffff'));
                $inset = (int) ($big * 0.08);
                self::square($img, $big, (int) ($big * 0.18), self::color($img, $b->accentColor()), $inset);
                self::square($img, $big, (int) ($big * 0.14), $brand, $inset + (int) ($big * 0.05));
            }
            self::letter($img, $big, $b->appName(), $maskable ? 0.36 : 0.42);
        }

        $out = imagecreatetruecolor($size, $size);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);
        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /** The uploaded square logo, else an uploaded raster favicon; null when only text is set. */
    private static function logo(Branding $b): ?\GdImage
    {
        foreach (['logo_path', 'favicon_path'] as $column) {
            $path = $b->{$column};
            if (! $path || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true)
                || ! Storage::disk('local')->exists($path)) {
                continue;
            }
            $image = @imagecreatefromstring(Storage::disk('local')->get($path));
            if ($image) {
                return $image;
            }
        }

        return null;
    }

    /** The logo, kept in proportion, centred in $share of the square. */
    private static function place(\GdImage $img, \GdImage $logo, int $big, float $share): void
    {
        [$w, $h] = [imagesx($logo), imagesy($logo)];
        $box = $big * $share;
        $ratio = min($box / $w, $box / $h);
        [$nw, $nh] = [(int) ($w * $ratio), (int) ($h * $ratio)];
        imagealphablending($img, true);
        imagecopyresampled($img, $logo, (int) (($big - $nw) / 2), (int) (($big - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    }

    /** A filled rounded square, $inset in from each edge. */
    private static function square(\GdImage $img, int $big, int $radius, int $color, int $inset = 0): void
    {
        [$a, $z] = [$inset, $big - 1 - $inset];
        $r = max(0, min($radius, (int) (($z - $a) / 2)));
        imagefilledrectangle($img, $a + $r, $a, $z - $r, $z, $color);
        imagefilledrectangle($img, $a, $a + $r, $z, $z - $r, $color);
        foreach ([[$a + $r, $a + $r], [$z - $r, $a + $r], [$a + $r, $z - $r], [$z - $r, $z - $r]] as [$x, $y]) {
            imagefilledellipse($img, $x, $y, $r * 2, $r * 2, $color);
        }
    }

    private static function letter(\GdImage $img, int $big, string $name, float $share): void
    {
        $letter = mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: 'H';
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $white = self::color($img, '#ffffff');
        if (! is_file($font)) {
            imagestring($img, 5, (int) ($big / 2 - 4), (int) ($big / 2 - 8), $letter, $white);

            return;
        }
        $size = $big * $share;
        $box = imagettfbbox($size, 0, $font, $letter);
        $x = ($big - ($box[2] - $box[0])) / 2 - $box[0];
        $y = ($big - ($box[1] - $box[7])) / 2 - $box[7];
        imagettftext($img, $size, 0, (int) $x, (int) $y, $white, $font, $letter);
    }

    private static function color(\GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
