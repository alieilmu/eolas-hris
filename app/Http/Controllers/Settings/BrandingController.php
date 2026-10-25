<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use App\Support\Branding\Palette;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → White Label (Super Admin only): the app and company name, the logos, the
 * favicon and the two colours every page is drawn in. The files are served by file(),
 * also to signed-out visitors (the login page shows them).
 */
class BrandingController extends Controller
{
    private const DIR = 'branding';

    public function edit(): Response
    {
        $b = Branding::current();

        return Inertia::render('Settings/Branding', [
            'values' => [
                'app_name' => $b->app_name ?? '',
                'company_name' => $b->company_name ?? '',
                'tagline' => $b->tagline ?? '',
                'brand_color' => $b->brandColor(),
                'accent_color' => $b->accentColor(),
            ],
            'files' => collect(Branding::FILES)->map(fn (string $column, string $type) => $b->fileUrl($type))->all(),
            'defaults' => ['app_name' => (string) config('app.name', 'HRIS'), 'brand_color' => Branding::DEFAULT_BRAND, 'accent_color' => Branding::DEFAULT_ACCENT],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $image = File::types(['png', 'jpg', 'jpeg', 'webp'])->max(2048);
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:60'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', $image],
            'logo-full' => ['nullable', $image],
            'favicon' => ['nullable', File::types(['png', 'ico', 'webp'])->max(512)],
            'delete' => ['nullable', 'array'],
            'delete.*' => ['string'],
        ], [
            'brand_color.regex' => 'Warna utama harus kode hex, mis. #3f5f8f.',
            'accent_color.regex' => 'Warna aksen harus kode hex, mis. #f2b705.',
        ], [
            'app_name' => 'Nama aplikasi',
            'company_name' => 'Nama perusahaan',
            'logo' => 'Logo',
            'logo-full' => 'Logo lengkap',
            'favicon' => 'Favicon',
        ]);

        $b = Branding::current();
        $values = [
            'app_name' => self::text($data['app_name'] ?? null),
            'company_name' => self::text($data['company_name'] ?? null),
            'tagline' => self::text($data['tagline'] ?? null),
            'brand_color' => Palette::normalize($data['brand_color']),
            'accent_color' => Palette::normalize($data['accent_color']),
        ];
        foreach (Branding::FILES as $type => $column) {
            $old = $b->{$column};
            if ($request->hasFile($type)) {
                $file = $request->file($type);
                $values[$column] = $file->storeAs(self::DIR, $type.'-'.now()->format('YmdHis').'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), 'local');
            } elseif (in_array($type, $data['delete'] ?? [], true)) {
                $values[$column] = null;
            } else {
                continue;
            }
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        }
        // touch: the ?v= of the file URLs changes, so browsers fetch the new logo.
        $b->fill($values)->touch();
        Branding::forget();
        Inertia::flash('success', 'White label disimpan. Muat ulang halaman lain yang sedang terbuka untuk melihat perubahannya.');

        return back();
    }

    /** An uploaded logo / favicon; without an uploaded favicon, one drawn from the initial and the colours. */
    public function file(string $type): StreamedResponse|HttpResponse
    {
        $b = Branding::current();
        $path = $b->{Branding::FILES[$type]};
        $headers = ['Cache-Control' => 'private, max-age=604800', 'X-Content-Type-Options' => 'nosniff'];

        if ($path && Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->response($path, null, $headers + [
                // An SVG only ever comes from the installation itself; still, no scripts in it.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            ]);
        }
        abort_unless($type === 'favicon', 404);

        return response(self::initialIcon($b), 200, $headers + ['Content-Type' => 'image/svg+xml']);
    }

    /** A rounded square with the first letter of the app name, in the brand colours. */
    private static function initialIcon(Branding $b): string
    {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr(trim($b->appName()), 0, 1)) ?: 'H', ENT_XML1);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
              <rect x="2" y="2" width="60" height="60" rx="14" fill="#fff"/>
              <rect x="8" y="8" width="48" height="48" rx="11" fill="{$b->brandColor()}" stroke="{$b->accentColor()}" stroke-width="5"/>
              <text x="32" y="43" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700" fill="#fff">{$letter}</text>
            </svg>
            SVG;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
