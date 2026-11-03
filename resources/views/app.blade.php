<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        @php($branding = \App\Models\Branding::current())
        <title inertia>{{ $branding->appName() }}</title>
        <meta name="app-name" content="{{ $branding->appName() }}">

        <link rel="icon" href="{{ $branding->toShare()['favicon_url'] }}">
        {{-- The app on the phone (PWA): the manifest is per workspace, so it is fetched with the session cookie. --}}
        <link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
        <meta name="theme-color" content="{{ $branding->brandColor() }}">
        <link rel="apple-touch-icon" href="{{ $branding->appIconUrl(180) }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ mb_strimwidth($branding->appName(), 0, 12, '') }}">
        @fonts
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
        {{-- White label colours: the brand / accent scales of app.css, drawn from the two chosen colours. --}}
        <style>{!! \App\Support\Branding\Palette::css($branding->brandColor(), $branding->accentColor()) !!}</style>
    </head>
    <body class="h-full bg-slate-50 font-sans text-slate-900">
        @inertia
    </body>
</html>
