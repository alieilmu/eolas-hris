<?php

namespace Database\Seeders;

use App\Models\Branding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The Eloas white label: green and white, with the Eloas leaf as logo and favicon, for the
 * main database and every demo workspace. Replaces whatever the brandings migration copied in.
 */
class EloasBrandingSeeder extends Seeder
{
    /** The leaf on a green rounded square. */
    public const ICON = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
          <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22c55e"/><stop offset="1" stop-color="#15803d"/></linearGradient></defs>
          <rect width="64" height="64" rx="16" fill="url(#g)"/>
          <path d="M45 17c-14 0-25 8-25 21 0 3 .8 5.6 2.2 7.6C25 36 32 30 40 27c-7 4.5-13 10.5-16.3 20.2 2 .8 4.2 1.3 6.6 1.3C42 48.5 47 37 47 24c0-2.6-.4-5-2-7z" fill="#fff"/>
        </svg>
        SVG;

    public function run(?string $company = null): void
    {
        $disk = Storage::disk('local');
        $disk->deleteDirectory('branding');
        $disk->put('branding/eloas-logo.svg', self::ICON);
        $disk->put('branding/eloas-favicon.svg', self::ICON);

        (Branding::query()->first() ?? new Branding)->fill([
            'app_name' => 'Eloas HRIS',
            'company_name' => $company ?: 'Eloas',
            'tagline' => 'Human Capital Management System',
            'brand_color' => Branding::DEFAULT_BRAND,
            'accent_color' => Branding::DEFAULT_ACCENT,
            'logo_path' => 'branding/eloas-logo.svg',
            'logo_full_path' => null,
            'favicon_path' => 'branding/eloas-favicon.svg',
        ])->save();
        Branding::forget();
    }
}
