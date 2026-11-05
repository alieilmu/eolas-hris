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
     * release) and the offline page with the files it runs (Absen HP without a signal); pages
     * themselves always come from the server, so no one's data stays on the phone. A new
     * release gives a new version, which replaces the old.
     */
    public function serviceWorker(): Response
    {
        $manifest = public_path('build/manifest.json');
        $version = substr(md5((is_file($manifest) ? (string) filemtime($manifest).md5_file($manifest) : 'dev').'|'.config('app.version', '')), 0, 12);
        $b = Branding::current();
        $precache = json_encode(['/offline', $b->appIconUrl(192), ...self::offlineAssets($manifest)], JSON_UNESCAPED_SLASHES);
        $name = json_encode((string) $b->appName(), JSON_UNESCAPED_UNICODE);
        $icon = json_encode($b->appIconUrl(192), JSON_UNESCAPED_SLASHES);

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

            // Notifikasi push: shown even when the app is closed; a tap opens its page.
            self.addEventListener('push', (event) => {
                let data = {};
                try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
                event.waitUntil(self.registration.showNotification(data.title || {$name}, {
                    body: data.body || '',
                    icon: {$icon},
                    badge: {$icon},
                    tag: data.tag || undefined,
                    renotify: !!data.tag,
                    data: { url: data.url || '/' },
                }));
            });

            self.addEventListener('notificationclick', (event) => {
                event.notification.close();
                const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
                if (target.origin !== self.location.origin) return;
                event.waitUntil(
                    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
                        const open = list.find((c) => new URL(c.url).origin === self.location.origin);
                        if (open) return open.focus().then((c) => (c || open).navigate(target.href)).catch(() => self.clients.openWindow(target.href));
                        return self.clients.openWindow(target.href);
                    }),
                );
            });
            JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * The built files the offline page loads (its script, the chunks it imports, the styles),
     * from Vite's manifest, so they are on the phone before the signal goes.
     *
     * @return list<string>
     */
    private static function offlineAssets(string $manifest): array
    {
        $entries = is_file($manifest) ? (json_decode((string) file_get_contents($manifest), true) ?: []) : [];
        $files = [];
        $walk = function (string $key) use (&$walk, &$files, $entries) {
            $entry = $entries[$key] ?? null;
            if (! $entry || isset($files[$entry['file']])) {
                return;
            }
            $files[$entry['file']] = true;
            foreach ($entry['css'] ?? [] as $css) {
                $files[$css] = true;
            }
            foreach ($entry['imports'] ?? [] as $import) {
                $walk($import);
            }
        };
        $walk('resources/js/offline.tsx');
        $walk('resources/css/app.css');

        return array_map(fn (string $f) => '/build/'.$f, array_keys($files));
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline', ['b' => Branding::current()])->header('Cache-Control', 'no-cache');
    }
}
