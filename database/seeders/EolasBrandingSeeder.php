<?php

namespace Database\Seeders;

use App\Models\Branding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The Eolas white label: green and white, the Eolas wordmark as the full logo and its "e"
 * (white on green) as the logo and favicon, for the main database and every demo workspace.
 * Replaces whatever the brandings migration copied in. Images: database/seeders/assets.
 */
class EolasBrandingSeeder extends Seeder
{
    private const ASSETS = __DIR__.'/assets';

    public function run(?string $company = null): void
    {
        $disk = Storage::disk('local');
        $disk->deleteDirectory('branding');
        $icon = file_get_contents(self::ASSETS.'/eolas-icon.png');
        $disk->put('branding/eolas-icon.png', $icon);
        $disk->put('branding/eolas-favicon.png', $icon);
        $disk->put('branding/eolas-logo-full.png', file_get_contents(self::ASSETS.'/eolas-logo-full.png'));

        (Branding::query()->first() ?? new Branding)->fill([
            'app_name' => 'Eolas HRIS',
            'company_name' => $company ?: 'Eolas',
            'tagline' => 'Human Capital Management System',
            'brand_color' => Branding::DEFAULT_BRAND,
            'accent_color' => Branding::DEFAULT_ACCENT,
            'logo_path' => 'branding/eolas-icon.png',
            'logo_full_path' => 'branding/eolas-logo-full.png',
            'favicon_path' => 'branding/eolas-favicon.png',
        ])->save();
        Branding::forget();
    }
}
