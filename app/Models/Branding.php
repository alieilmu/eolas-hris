<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * White label (one row, Settings → White Label): the app and company name, the logos,
 * the favicon and the two colours. What is left empty falls back to a neutral default.
 */
#[Fillable(['app_name', 'company_name', 'tagline', 'brand_color', 'accent_color', 'logo_path', 'logo_full_path', 'favicon_path'])]
class Branding extends Model
{
    public const DEFAULT_BRAND = '#15803d';

    public const DEFAULT_ACCENT = '#86efac';

    /** jenis in the URL => column. */
    public const FILES = ['logo' => 'logo_path', 'logo-full' => 'logo_full_path', 'favicon' => 'favicon_path'];

    /** Read once per request (kept in the container, so nothing outlives the request). */
    public static function current(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }
        try {
            $branding = self::query()->first() ?? self::query()->create([]);
        } catch (QueryException) {
            // Before the migration ran (fresh install, artisan commands): the defaults.
            $branding = new self;
        }

        return app()->instance(self::class, $branding);
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }

    public function appName(): string
    {
        return $this->app_name ?: (string) config('app.name', 'HRIS');
    }

    public function brandColor(): string
    {
        return $this->brand_color ?: self::DEFAULT_BRAND;
    }

    public function accentColor(): string
    {
        return $this->accent_color ?: self::DEFAULT_ACCENT;
    }

    /** /branding/logo?v=…, or null when none was uploaded. The version makes a new upload show at once. */
    public function fileUrl(string $jenis): ?string
    {
        return $this->{self::FILES[$jenis]} ? "/branding/{$jenis}?v=".($this->updated_at?->timestamp ?? 0) : null;
    }

    /** For every page (Inertia shared props). */
    public function toShare(): array
    {
        return [
            'app_name' => $this->appName(),
            'company_name' => $this->company_name,
            'tagline' => $this->tagline,
            'brand_color' => $this->brandColor(),
            'accent_color' => $this->accentColor(),
            'logo_url' => $this->fileUrl('logo'),
            'logo_full_url' => $this->fileUrl('logo-full'),
            // Always there: an uploaded one, or one drawn from the initial and the colours.
            'favicon_url' => '/branding/favicon?v='.($this->updated_at?->timestamp ?? 0),
        ];
    }
}
