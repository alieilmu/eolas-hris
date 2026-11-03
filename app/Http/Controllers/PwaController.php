<?php

namespace App\Http\Controllers;

use App\Models\Branding;
use App\Support\Pwa\AppIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Eolas as an app on the phone (PWA): the manifest with the company's name, colours and icon
 * (White Label), the icons, the service worker, and the page it shows without a connection.
 * Open to signed-out visitors: the phone reads them before anyone signs in.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $b = Branding::current();
        $name = $b->appName();
        $icons = [];
        foreach ([192, 512] as $size) {
            $icons[] = ['src' => $b->appIconUrl($size), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $b->appIconUrl($size, true), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        return response()->json([
            'id' => '/',
            'name' => $b->company_name ? "{$name} — {$b->company_name}" : $name,
            'short_name' => mb_strimwidth($name, 0, 12, ''),
            'description' => $b->tagline ?: 'Portal karyawan dan HR',
            'lang' => 'id',
            'start_url' => '/?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f8fafc',
            'theme_color' => $b->brandColor(),
            'icons' => $icons,
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'no-cache'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** /app-icon/192.png, /app-icon/512-maskable.png … */
    public function icon(string $icon): Response
    {
        $size = (int) $icon;
        abort_unless(in_array($size, AppIcon::SIZES, true), 404);

        return response(AppIcon::png(Branding::current(), $size, str_ends_with($icon, '-maskable')), 200, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=604800', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The service worker: keeps the built scripts and styles (their names change with every
     * release) and the offline page; pages themselves always come from the server, so no
     * one's data stays on the phone. A new release gives a new version, which replaces the old.
     */
    public function serviceWorker(): Response
    {
        $manifest = public_path('build/manifest.json');
        $version = substr(md5((is_file($manifest) ? (string) filemtime($manifest).md5_file($manifest) : 'dev').'|'.config('app.version', '')), 0, 12);
        $b = Branding::current();
        $precache = json_encode(['/offline', $b->appIconUrl(192)], JSON_UNESCAPED_SLASHES);

        $js = <<<JS
            // Eolas service worker {$version}
            const CACHE = 'eolas-{$version}';
            const OFFLINE = '/offline';

            self.addEventListener('install', (event) => {
                event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll({$precache})).then(() => self.skipWaiting()));
            });

            self.addEventListener('activate', (event) => {
                event.waitUntil(
                    caches.keys()
                        .then((keys) => Promise.all(keys.filter((key) => key.startsWith('eolas-') && key !== CACHE).map((key) => caches.delete(key))))
                        .then(() => self.clients.claim()),
                );
            });

            self.addEventListener('fetch', (event) => {
                const request = event.request;
                if (request.method !== 'GET') return;
                const url = new URL(request.url);
                if (url.origin !== self.location.origin) return;

                // Built files never change under the same name: the cache first.
                if (url.pathname.startsWith('/build/')) {
                    event.respondWith(
                        caches.match(request).then((hit) => hit || fetch(request).then((response) => {
                            if (response.ok) {
                                const copy = response.clone();
                                caches.open(CACHE).then((cache) => cache.put(request, copy));
                            }
                            return response;
                        })),
                    );
                    return;
                }

                // A page: from the server; without a connection, the offline page.
                if (request.mode === 'navigate') {
                    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
                }
            });
            JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline', ['b' => Branding::current()])->header('Cache-Control', 'no-cache');
    }
}
